<?php
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

$host = $_SERVER['HTTP_HOST'] ?? '';
$domain = 'zaloriatech.com';
$subdomain = '';
// FIX: force lme-group pour domaines custom, localhost et IP
if (stripos($host, 'lme-group') !== false || stripos($host, 'aurora') !== false || $host === 'localhost' || $host === '127.0.0.1' || filter_var(explode(':', $host)[0], FILTER_VALIDATE_IP) || strpos($host, 'e2b.dev') !== false) {
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

$stmtConcours = $pdo->prepare("SELECT concours_id, nom_concours, url_concours, logo_concours, logo_extension, date_ouverture, date_cloture, etat_concours, site_id, arret_manuel, cree_le, modifie_le, results_visible, verification_active, results_live FROM concours WHERE site_id = ? AND etat_concours = 'actif' AND arret_manuel = 0 AND NOW() BETWEEN date_ouverture AND date_cloture ORDER BY date_ouverture ASC");
$stmtConcours->execute([$siteId]);
$concoursList = $stmtConcours->fetchAll();
if (empty($concoursList)) {
    $stmtConcoursFallback = $pdo->prepare("SELECT concours_id, nom_concours, url_concours, logo_concours, logo_extension, date_ouverture, date_cloture, etat_concours, site_id, arret_manuel, cree_le, modifie_le, results_visible, verification_active, results_live FROM concours WHERE site_id = ? ORDER BY date_ouverture ASC LIMIT 1");
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

$participantes = [];
$heroCandidates = [];
$allCandidates = [];
$etapes = [];
$candidatesByEtape = [];
$totalVotesAll = 1;
if ($concoursId > 0) {
    $stmtPart = $pdo->prepare("SELECT p.participante_id, p.code_participante, p.nom_complet, p.age, p.ville_origine, p.niveau_etudes, p.taille_en_cm, p.biographie, p.cause_soutenue, p.situation_actuelle, p.inscrite_le, p.modifie_le, (SELECT m.photo_officielle FROM medias_participantes m WHERE m.participante_id = p.participante_id ORDER BY m.est_photo_principale DESC, m.ajoute_le DESC LIMIT 1) AS photo_officielle, COALESCE(SUM(t.votes_accordes), 0) AS total_votes FROM participantes p LEFT JOIN transactions_votes t ON p.participante_id = t.participante_id AND t.etat_paiement = 'confirme' WHERE p.concours_id = ? AND p.situation_actuelle = 'active' GROUP BY p.participante_id ORDER BY p.code_participante ASC");
    $stmtPart->execute([$concoursId]);
    $participantes = $stmtPart->fetchAll();

    $stmtEtapes = $pdo->prepare("SELECT e.etape_id, e.concours_id, e.type_etape_id, e.numero_ordre, e.date_ouverture, e.date_cloture, e.votes_actifs, e.etape_terminee, e.cree_le, e.modifie_le, t.nom_etape, t.description_etape FROM etapes_du_concours e JOIN types_etapes t ON e.type_etape_id = t.type_etape_id WHERE e.concours_id = ? AND e.etape_terminee = 0 ORDER BY e.numero_ordre ASC");
    $stmtEtapes->execute([$concoursId]);
    $etapes = $stmtEtapes->fetchAll();

    foreach ($etapes as $etape) {
        $etapeId = $etape['etape_id'];
        $stmt = $pdo->prepare("SELECT p.participante_id, p.code_participante, p.nom_complet, p.age, p.ville_origine, p.niveau_etudes, p.taille_en_cm, p.biographie, p.cause_soutenue, p.situation_actuelle, p.inscrite_le, p.modifie_le, (SELECT m.photo_officielle FROM medias_participantes m WHERE m.participante_id = p.participante_id ORDER BY m.est_photo_principale DESC, m.ajoute_le DESC LIMIT 1) AS photo_officielle, COALESCE(SUM(t.votes_accordes), 0) AS total_votes FROM participantes p JOIN parcours_participantes pp ON p.participante_id = pp.participante_id JOIN etapes_du_concours e ON pp.etape_id = e.etape_id LEFT JOIN transactions_votes t ON p.participante_id = t.participante_id AND t.etat_paiement = 'confirme' AND (t.etape_id = ? OR t.etape_id IS NULL) WHERE p.concours_id = ? AND p.situation_actuelle = 'active' AND pp.etape_id = ? AND e.etape_terminee = 0 GROUP BY p.participante_id ORDER BY p.code_participante ASC");
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

function esc($s) {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'votes_data') {
    header('Content-Type: application/json');
    $ajaxConcoursId = isset($_GET['concours_id']) ? (int)$_GET['concours_id'] : $concoursId;
    if ($ajaxConcoursId > 0) {
        $stmtRank = $pdo->prepare("SELECT p.participante_id, p.code_participante, p.nom_complet, COALESCE(SUM(t.votes_accordes), 0) AS total_votes FROM participantes p LEFT JOIN transactions_votes t ON p.participante_id = t.participante_id AND t.etat_paiement = 'confirme' WHERE p.concours_id = ? AND p.situation_actuelle = 'active' AND EXISTS (SELECT 1 FROM parcours_participantes pp JOIN etapes_du_concours e ON pp.etape_id = e.etape_id WHERE pp.participante_id = p.participante_id AND e.etape_terminee = 0) GROUP BY p.participante_id ORDER BY total_votes DESC");
        $stmtRank->execute([$ajaxConcoursId]);
        $ranking = $stmtRank->fetchAll();
        $totalVotesAll = 0;
        foreach ($ranking as $r) $totalVotesAll += $r['total_votes'];
        if ($totalVotesAll == 0) $totalVotesAll = 1;
        $stmtLatest = $pdo->prepare("SELECT t.transaction_id, t.participante_id, t.votes_accordes, t.confirme_le, t.numero_telephone, p.nom_complet, p.code_participante FROM transactions_votes t JOIN participantes p ON t.participante_id = p.participante_id WHERE t.etat_paiement = 'confirme' AND p.concours_id = ? AND EXISTS (SELECT 1 FROM parcours_participantes pp JOIN etapes_du_concours e ON pp.etape_id = e.etape_id WHERE pp.participante_id = p.participante_id AND e.etape_terminee = 0) ORDER BY t.confirme_le DESC LIMIT 20");
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
    <style>
/* ═══════ TOKENS ═══════ */
:root { --gold: #C9A84C; --gold-light: #E8CC80; --gold-dim: rgba(201,168,76,.15); --gold-border: rgba(201,168,76,.32); --black: #080808; --white: #FFFFFF; --muted: rgba(255,255,255,.46); --overlay: linear-gradient(108deg, rgba(8,8,8,.92) 0%, rgba(8,8,8,.52) 55%, rgba(8,8,8,.06) 100%); --bg2: #0A0A0A; --gold-lt: var(--gold-light); --gold-bdr: var(--gold-border); --muted2: rgba(255,255,255,.4); }
*,* ::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Outfit', sans-serif; background: var(--black); color: #fff; -webkit-font-smoothing: antialiased; }
a { text-decoration: none; color: inherit; }
img { display: block; max-width: 100%; }

/* ═══════ HERO ═══════ */
.ml-hero { position: relative; width: 100%; height: 100svh; min-height: 620px; overflow: hidden; background-image: url('texture.jpg'); background-repeat:no-repeat; background-size: cover; }
.ml-hero__slide { position: absolute; inset: 0; background-size: cover; background-position: center 20%; opacity: 0; transform: scale(1.07); transition: opacity .9s cubic-bezier(.4,0,.2,1), transform 7s cubic-bezier(.25,.46,.45,.94); }
.ml-hero__slide.is-active { opacity: 1; transform: scale(1); }
.ml-hero__overlay { position: absolute; inset: 0; background: var(--overlay); z-index: 2; }
.ml-hero__overlay::after { content: ''; position: absolute; inset: 0; background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='4'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E"); pointer-events: none; opacity: .5; }
.ml-hero__line { position: absolute; left: 80px; top: 0; bottom: 0; width: 1px; background: linear-gradient(to bottom, transparent, var(--gold-border) 30%, var(--gold-border) 70%, transparent); z-index: 3; opacity: .4; }
.ml-hero__content { position: absolute; left: 110px; top: 50%; transform: translateY(-50%); z-index: 10; max-width: 660px; }
.ml-hero__badge { display: inline-flex; align-items: center; gap: 8px; font-family: 'Outfit', sans-serif; font-size: 10px; font-weight: 700; letter-spacing: .22em; text-transform: uppercase; color: var(--gold); border: 1px solid var(--gold-border); padding: 6px 15px; border-radius: 2px; background: var(--gold-dim); backdrop-filter: blur(8px); margin-bottom: 28px; animation: ml-up .8s .1s both; }
.ml-badge-dot { width: 5px; height: 5px; border-radius: 50%; background: var(--gold); animation: ml-pulse 2s infinite; }
@keyframes ml-pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.4;transform:scale(1.7)} }
.ml-hero__title { font-family: 'Cormorant Garamond', serif; font-weight: 300; font-size: clamp(2.5rem, 9vw, 4.5rem); line-height: .92; text-transform: uppercase; color: var(--white); letter-spacing: -.02em; margin: 0 0 6px; animation: ml-up .9s .2s both; }
.ml-hero__title strong { display: block; font-weight: 700; font-style: italic; color: var(--gold-light); }
.ml-hero__descriptor { display: flex; align-items: center; gap: 14px; margin: 20px 0 10px; animation: ml-up .9s .35s both; }
.ml-descriptor-line { width: 36px; height: 1px; background: var(--gold); flex-shrink: 0; }
.ml-hero__subtitle { font-family: 'Cormorant Garamond', serif; font-size: 1.5rem; font-weight: 600; font-style: italic; color: var(--gold-light); letter-spacing: .06em; text-transform: uppercase; }
.ml-hero__desc { font-family: 'Outfit', sans-serif; font-size: .95rem; font-weight: 300; color: var(--muted); max-width: 400px; line-height: 1.75; margin-bottom: 36px; animation: ml-up .9s .45s both; }
.ml-hero__btns { display: flex; gap: 14px; flex-wrap: wrap; animation: ml-up .9s .55s both; }
.ml-btn { display: inline-flex; align-items: center; gap: 9px; font-family: 'Outfit', sans-serif; font-size: .875rem; font-weight: 600; letter-spacing: .04em; padding: 14px 28px; border-radius: 3px; cursor: pointer; text-decoration: none; border: none; transition: all .25s ease; }
.ml-btn--primary { background: var(--gold); color: var(--black); }
.ml-btn--primary:hover { background: var(--gold-light); transform: translateY(-2px); box-shadow: 0 12px 32px rgba(201,168,76,.35); }
.ml-btn--outline { background: transparent; color: var(--white); border: 1px solid rgba(255,255,255,.28); backdrop-filter: blur(8px); }
.ml-btn--outline:hover { border-color: var(--gold-border); color: var(--gold-light); transform: translateY(-2px); background: var(--gold-dim); }
.ml-hero__counter { position: absolute; left: 34px; top: 50%; transform: translateY(-50%) rotate(-90deg); z-index: 10; display: flex; align-items: center; gap: 10px; font-family: 'Outfit', sans-serif; font-size: 11px; font-weight: 500; letter-spacing: .12em; color: var(--muted); }
.ml-counter-current { color: var(--gold); font-size: 13px; font-weight: 700; }
.ml-counter-bar { width: 30px; height: 1px; background: rgba(255,255,255,.2); position: relative; overflow: hidden; }
.ml-counter-bar::after { content: ''; position: absolute; left: 0; top: 0; bottom: 0; background: var(--gold); width: var(--prog, 25%); transition: width .6s ease; }
.ml-hero__ghost { position: absolute; right: 32px; bottom: 110px; z-index: 5; font-family: 'Cormorant Garamond', serif; font-size: 5rem; font-weight: 700; font-style: italic; color: rgba(255,255,255,.05); letter-spacing: .04em; pointer-events: none; user-select: none; }
.ml-hero__nav { position: absolute; right: 32px; top: 50%; transform: translateY(-50%); z-index: 10; display: flex; flex-direction: column; gap: 10px; }
.ml-nav-btn { width: 44px; height: 44px; border-radius: 50%; border: 1px solid var(--gold-border); background: var(--gold-dim); backdrop-filter: blur(10px); display: flex; align-items: center; justify-content: center; color: var(--gold-light); cursor: pointer; transition: all .22s ease; }
.ml-nav-btn:hover { background: rgba(201,168,76,.32); border-color: var(--gold); transform: scale(1.1); }
.ml-hero__progress { position: absolute; bottom: 0; left: 0; right: 0; height: 2px; background: rgba(255,255,255,.07); z-index: 10; }
.ml-progress-fill { height: 100%; background: linear-gradient(90deg, var(--gold), var(--gold-light)); width: 0%; border-radius: 0 2px 2px 0; }
@keyframes ml-up { from { opacity:0; transform:translateY(20px); } to { opacity:1; transform:translateY(0); } }

/* ═══════ HEADER ═══════ */
.ml-header { position: fixed; top: 0; left: 0; right: 0; z-index: 1000; padding: 0 80px; height: 80px; display: flex; align-items: center; justify-content: space-between; background: transparent; transition: background .4s ease, box-shadow .4s ease, height .3s ease; }
.ml-header::after { content: ''; position: absolute; bottom: 0; left: 60px; right: 60px; height: 1px; background: rgba(255,255,255,.08); transition: opacity .4s; }
.ml-header.is-sticky { background: var(--white); box-shadow: 0 2px 32px rgba(0,0,0,.1); height: 64px; }
.ml-header.is-sticky::after { opacity: 0; }
.ml-header.is-sticky .ml-logo__text { color: var(--black); }
.ml-header.is-sticky .ml-nav__link { color: var(--text-dark, #1A1A1A); }
.ml-header.is-sticky .ml-nav__link:hover { color: var(--gold); }
.ml-header.is-sticky .ml-nav__link.is-active { background: var(--gold); color: #fff; }
.ml-header.is-sticky .ml-menu-toggle span { background: var(--black); }
.ml-logo { display: flex; align-items: center; gap: 11px; text-decoration: none; flex-shrink: 0; }
.ml-logo img { width: 90px; height: 50px; object-fit: contain; transition: height .3s ease; }
.ml-header.is-sticky .ml-logo img { height: 42px; }
.ml-logo__text { font-family: 'Cormorant Garamond', serif; font-size: 1.35rem; font-weight: 700; color: #fff; letter-spacing: .02em; transition: color .3s; }
.ml-nav { display: flex; align-items: center; }
.ml-nav__list { display: flex; align-items: center; gap: 2px; list-style: none; }
.ml-nav__link { position: relative; display: inline-flex; align-items: center; gap: 5px; font-family: 'Outfit', sans-serif; font-size: .845rem; font-weight: 500; color: rgba(255,255,255,.82); text-decoration: none; padding: 7px 13px; border-radius: 6px; letter-spacing: .02em; transition: color .22s, background .22s; white-space: nowrap; }
.ml-nav__link::after { content: ''; position: absolute; bottom: 2px; left: 50%; right: 50%; height: 1.5px; background: var(--gold); border-radius: 1px; transition: left .25s ease, right .25s ease; }
.ml-nav__link:hover { color: #fff; }
.ml-nav__link:hover::after { left: 13px; right: 13px; }
.ml-nav__link.is-active { background: var(--gold); color: #fff; font-weight: 600; }
.ml-nav__link.is-active::after { display: none; }
.ml-nav__has-sub { position: relative; }
.ml-submenu { position: absolute; top: calc(100% + 12px); left: 50%; transform: translateX(-50%) translateY(-8px); width: 260px; background: #111; border: 1px solid rgba(255,255,255,.1); border-radius: 16px; padding: 10px; box-shadow: 0 20px 60px rgba(0,0,0,.55); opacity: 0; pointer-events: none; transition: opacity .28s ease, transform .28s ease; z-index: 500; }
.ml-submenu::before { content: ''; position: absolute; top: 0; left: 24px; right: 24px; height: 2px; background: linear-gradient(90deg, transparent, var(--gold) 30%, var(--gold-light) 70%, transparent); }
.ml-submenu::after { content: ''; position: absolute; top: -7px; left: 50%; transform: translateX(-50%); width: 14px; height: 7px; background: #111; clip-path: polygon(50% 0%, 0% 100%, 100% 100%); }
.ml-nav__has-sub:hover .ml-submenu { opacity: 1; pointer-events: all; transform: translateX(-50%) translateY(0); }
.ml-header.is-sticky .ml-submenu { background: #fff; border-color: rgba(0,0,0,.1); box-shadow: 0 16px 48px rgba(0,0,0,.15); }
.ml-header.is-sticky .ml-submenu::after { background: #fff; }
.ml-submenu__item { display: flex; align-items: center; gap: 13px; padding: 11px 13px; border-radius: 10px; text-decoration: none; color: rgba(255,255,255,.78); font-family: 'Outfit', sans-serif; font-size: .83rem; font-weight: 500; transition: background .22s, color .22s, transform .22s; cursor: pointer; }
.ml-submenu__item:hover { background: var(--gold-dim); color: #fff; transform: translateX(3px); }
.ml-header.is-sticky .ml-submenu__item { color: var(--text-dark, #1A1A1A); }
.ml-header.is-sticky .ml-submenu__item:hover{ background: rgba(201,168,76,.1); color: var(--black); }
.ml-submenu__icon { width: 38px; height: 38px; min-width: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 1.05rem; transition: transform .22s; }
.ml-submenu__item:hover .ml-submenu__icon { transform: scale(1.1); }
.ml-submenu__icon--vote { background: var(--gold-dim); border: 1px solid var(--gold-border); color: var(--gold); }
.ml-submenu__icon--closed { background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.25); color: #f87171; }
.ml-submenu__icon--results { background: rgba(34,197,94,.08); border: 1px solid rgba(34,197,94,.25); color: #4ade80; }
.ml-submenu__text { flex: 1; }
.ml-submenu__label { font-weight: 600; font-size: .83rem; line-height: 1.1; }
.ml-submenu__desc { font-size: .71rem; font-weight: 300; color: rgba(255,255,255,.38); margin-top: 2px; }
.ml-header.is-sticky .ml-submenu__desc { color: #6B6B6B; }
.ml-submenu__badge { font-size: .62rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; padding: 2px 7px; border-radius: 999px; }
.ml-submenu__badge--live { background: rgba(34,197,94,.15); color: #4ade80; border: 1px solid rgba(34,197,94,.3); }
.ml-submenu__badge--closed { background: rgba(239,68,68,.1); color: #f87171; border: 1px solid rgba(239,68,68,.25); }
.ml-submenu__divider { height: 1px; background: rgba(255,255,255,.06); margin: 6px 2px; }
.ml-header.is-sticky .ml-submenu__divider { background: rgba(0,0,0,.08); }
.ml-menu-toggle { display: none; flex-direction: column; justify-content: center; gap: 5px; width: 40px; height: 40px; padding: 8px; cursor: pointer; border: 1px solid var(--gold-border); border-radius: 8px; background: var(--gold-dim); backdrop-filter: blur(6px); transition: background .22s, border-color .22s; }
.ml-menu-toggle:hover { background: rgba(201,168,76,.25); border-color: var(--gold); }
.ml-menu-toggle span { display: block; width: 100%; height: 1.5px; background: #fff; border-radius: 2px; transition: transform .3s ease, opacity .3s ease; transform-origin: center; }
.ml-menu-toggle.is-open span:nth-child(1) { transform: translateY(6.5px) rotate(45deg); }
.ml-menu-toggle.is-open span:nth-child(2) { opacity: 0; transform: scaleX(0); }
.ml-menu-toggle.is-open span:nth-child(3) { transform: translateY(-6.5px) rotate(-45deg); }
.ml-mobile-menu { position: fixed; top: 0; right: -100%; width: min(340px, 88vw); height: 100dvh; background: #0D0D0D; border-left: 1px solid rgba(255,255,255,.07); padding: 100px 28px 48px; z-index: 999; transition: right .4s cubic-bezier(.4,0,.2,1); overflow-y: auto; }
.ml-mobile-menu.is-open { right: 0; }
.ml-mobile-menu__list { list-style: none; display: flex; flex-direction: column; gap: 2px; }
.ml-mobile-menu__link { display: flex; align-items: center; gap: 10px; font-family: 'Outfit', sans-serif; font-size: 1rem; font-weight: 500; color: rgba(255,255,255,.75); text-decoration: none; padding: 11px 14px; border-radius: 8px; transition: color .22s, background .22s, transform .22s; }
.ml-mobile-menu__link:hover { color: var(--gold-light); background: var(--gold-dim); transform: translateX(4px); }
.ml-mobile-menu__link.is-active { color: var(--gold-light); background: var(--gold-dim); border-left: 2px solid var(--gold); padding-left: 12px; }
.ml-mobile-menu__item--has-sub { position: relative; }
.ml-mobile-menu__toggle { display: flex !important; align-items: center; justify-content: space-between; cursor: pointer; padding: 11px 14px; border-radius: 8px; transition: color .22s, background .22s; }
.ml-mobile-menu__toggle:hover { color: var(--gold-light); background: var(--gold-dim); }
.ml-mobile-menu__arrow { transition: transform .3s ease; margin-left: 8px; }
.ml-mobile-menu__arrow.open { transform: rotate(180deg); }
.ml-mobile-menu__sub { display: none; list-style: none; padding-left: 20px; margin: 4px 0 8px 0; }
.ml-mobile-menu__sub.open { display: block; }
.ml-mobile-menu__sub-link { display: flex; justify-content: space-between; align-items: center; padding: 8px 14px; border-radius: 6px; font-size: .9rem; color: rgba(255,255,255,.7); text-decoration: none; transition: color .2s, background .2s; }
.ml-mobile-menu__sub-link:hover { color: #fff; background: var(--gold-dim); }
.ml-mobile-menu__badge { font-size: .6rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; padding: 2px 8px; border-radius: 999px; background: rgba(16,185,129,.2); color: #10b981; }
.ml-mobile-menu__badge:empty { display: none; }
.ml-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); opacity: 0; pointer-events: none; transition: opacity .35s ease; z-index: 998; }
.ml-overlay.is-open { opacity: 1; pointer-events: all; }
@media (max-width: 1100px) { .ml-header { padding: 0 28px; } .ml-header::after { left: 28px; right: 28px; } .ml-nav { display: none; } .ml-menu-toggle { display: flex; } }
@media (min-width: 1400px) { .ml-header { padding: 0 100px; } .ml-header::after { left: 100px; right: 100px; } .ml-nav__link { font-size: .9rem; padding: 8px 15px; } }
@media (min-width: 1700px) { .ml-header { padding: 0 160px; } .ml-header::after { left: 160px; right: 160px; } }

@media (max-width: 1200px) { .ml-hero__content { left: 60px !important; max-width: 500px !important; } .ml-hero__title { font-size: clamp(3.5rem, 7vw, 6rem) !important; } .ml-hero__counter { left: 20px !important; } .ml-hero__nav { right: 20px !important; } }
@media (max-width: 992px) { .ml-hero__content { left: 40px !important; max-width: 400px !important; } .ml-hero__title { font-size: clamp(2.8rem, 6vw, 4.5rem) !important; } .ml-hero__subtitle { font-size: 1.2rem !important; } .ml-hero__desc { font-size: 0.85rem !important; max-width: 300px !important; } .ml-hero__btns .ml-btn { font-size: 0.8rem !important; padding: 12px 20px !important; } }
@media (max-width: 768px) { .ml-hero__content { left: 20px !important; max-width: 100% !important; padding-right: 20px !important; top: 55% !important; } .ml-hero__title { font-size: clamp(2.2rem, 5vw, 3.2rem) !important; } .ml-hero__subtitle { font-size: 1rem !important; } .ml-hero__desc { font-size: 0.8rem !important; max-width: 100% !important; margin-bottom: 20px !important; } .ml-hero__btns { flex-direction: column !important; align-items: flex-start !important; gap: 10px !important; } .ml-hero__btns .ml-btn { width: 100% !important; justify-content: center !important; font-size: 0.75rem !important; padding: 12px 16px !important; } .ml-hero__counter { display: none !important; } .ml-hero__ghost { display: none !important; } .ml-hero__nav { right: 10px !important; gap: 8px !important; } .ml-nav-btn { width: 36px !important; height: 36px !important; } .ml-hero__badge { font-size: 8px !important; padding: 4px 10px !important; margin-bottom: 16px !important; } }
@media (max-width: 480px) {.ml-header{padding: 0px 15px 0px 0px;} .ml-hero__content { width:95%;left: 16px !important; padding-right: 16px !important; top: 51% !important; } .ml-hero__title {text-align:center; font-size: clamp(1.8rem, 4.5vw, 2.4rem) !important; } .ml-hero__subtitle {text-align:center; font-size: 0.9rem !important; } .ml-hero__desc {text-align:center; font-size: 0.75rem !important; margin-bottom: 56px !important; } .ml-hero__btns .ml-btn { font-size: 0.7rem !important; padding: 10px 14px !important; } }
@media (min-width: 1400px) { .ml-hero__content { left: 130px; max-width: 720px; } .ml-hero__counter { left: 44px; } .ml-hero__nav { right: 44px; } .ml-hero__ghost { right: 44px; } }
@media (min-width: 1700px) { .ml-hero__content { left: 160px; max-width: 760px; } .ml-hero__counter { left: 60px; } .ml-hero__nav { right: 60px; } .ml-hero__ghost { right: 60px; } }
</style>
</head>
<body>
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
                <div class="ml-submenu">
                    <span class="submenu-header">Choisissez un concours</span>
                    <?php foreach ($concoursList as $c): ?>
                    <a href="?concours_id=<?= $c['concours_id'] ?>" class="submenu-item">
                        <?= esc($c['nom_concours']) ?>
                        <?php $now = time(); $fin = strtotime($c['date_cloture']); if ($now <= $fin) { echo '<span class="submenu-item-badge">' . (($now >= strtotime($c['date_ouverture'])) ? 'En cours' : 'À venir') . '</span>'; } ?>
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
                Compétition <svg class="ml-mobile-menu__arrow" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
            </div>
            <ul class="ml-mobile-menu__sub" id="competition-mobile-sub">
                <?php foreach ($concoursList as $c): ?>
                <li><a href="?concours_id=<?= $c['concours_id'] ?>" class="ml-mobile-menu__sub-link">
                    <?= esc($c['nom_concours']) ?>
                    <?php $now = time(); $fin = strtotime($c['date_cloture']); if ($now <= $fin) { echo '<span class="ml-mobile-menu__badge">' . (($now >= strtotime($c['date_ouverture'])) ? 'En cours' : 'À venir') . '</span>'; } ?>
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
        <?php foreach ($heroCandidates as $index => $c): $role = esc($c['niveau_etudes'] ?? '') . ' · ' . esc($c['ville_origine'] ?? 'Kinshasa'); ?>
        <?php $photoUrl = ''; if (!empty($c['photo_officielle'])) { $path = ltrim($c['photo_officielle'], '/'); if (strpos($path, 'admin/') !== 0) { $path = 'admin/' . $path; } $photoUrl = STOCKAGE_DOMAIN . '/' . $path; } ?>
        <div class="ml-hero__slide <?= $index === 0 ? 'is-active' : '' ?>" style="background-image:url('<?= esc($photoUrl) ?>');background-repeat:no-repeat;background-size:contain;background-position:right;" data-subtitle="<?= esc($c['nom_complet']) ?>" data-desc="<?= $role ?>"></div>
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
            <span class="ml-badge-dot"></span> LME GROUP · Kinshasa, République Démocratique du Congo
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

<script>
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
        [subtitle, descEl, ghost].forEach(el => {
            el.style.transition = 'opacity .3s, transform .3s';
            el.style.opacity = '0';
            el.style.transform = 'translateY(-10px)';
        });
        setTimeout(() => {
            subtitle.textContent = d.subtitle || '';
            descEl.textContent = d.desc || '';
            ghost.textContent = d.subtitle || '';
            [subtitle, descEl, ghost].forEach(el => {
                el.style.opacity = '1';
                el.style.transform = 'translateY(0)';
            });
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
</script>
</body>
</html>
