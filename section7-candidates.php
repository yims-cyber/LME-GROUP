<?php
function esc($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
function getCandidatePhotoUrl($photo_officielle) {
    if (empty($photo_officielle)) return 'https://images.unsplash.com/photo-1529626455594-4ff0802cfb7e?w=600&h=750&fit=crop';
    $p = ltrim($photo_officielle, '/');
    if (strpos($p, 'admin/') === 0) $p = substr($p, 6);
    $p = ltrim($p, '/');
    if (strpos($p, 'uploads/') !== 0) $p = 'uploads/' . $p;
    if (!defined('STOCKAGE_DOMAIN')) define('STOCKAGE_DOMAIN','https://gestion.zaloriatech.com');
    return STOCKAGE_DOMAIN . '/admin/' . $p;
}
if (!defined('STOCKAGE_DOMAIN')) define('STOCKAGE_DOMAIN','https://gestion.zaloriatech.com');

$dbHost = 'localhost:3306';
$dbName = 'mayi1275_zaloria_multisysteme';
$dbUser = 'mayi1275_zaloriatech';
$dbPass = '07/09/1996/O2switch';
try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) { die("Erreur de connexion : " . $e->getMessage()); }

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
    if (!$siteData) die("Aucun site trouvé.");
    $subdomain = $siteData['lien_unique'];
}
$siteId = $siteData['site_id'];
$siteName = $siteData['nom_entreprise'];

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
foreach ($concoursList as $c) { if ($c['concours_id'] === $selectedConcoursId) { $currentConcours = $c; break; } }
if (!$currentConcours && !empty($concoursList)) $currentConcours = $concoursList[0];
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
    foreach ($candidatesByEtape as $etapeId => $cands) { foreach ($cands as $cand) { $validHeroIds[$cand['participante_id']] = true; } }
    $heroCandidates = []; $allCandidates = [];
    foreach ($participantes as $p) { if (isset($validHeroIds[$p['participante_id']])) { $heroCandidates[] = $p; $allCandidates[] = $p; } }
    $totalVotesAll = 0;
    foreach ($allCandidates as $c) { $totalVotesAll += $c['total_votes']; }
    if ($totalVotesAll == 0) $totalVotesAll = 1;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Candidates — <?= esc($siteName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,700;1,300;1,700&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ═══════ CANDIDATES ═══════ */
.cd { position: relative; background: #FAFAF8; padding: 90px 60px 100px; overflow: hidden; }
.cd::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, transparent 0%, #C6973F 30%, #E2BD6E 50%, #C6973F 70%, transparent 100%); }
.cd__pattern { position: absolute; inset: 0; z-index: 0; background-image: url("data:image/svg+xml,%3Csvg width='40' height='40' viewBox='0 0 40 40' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' stroke='%23C6973F' stroke-opacity='0.05' stroke-width='0.5'%3E%3Cpath d='M20 0 L40 20 L20 40 L0 20 Z'/%3E%3C/g%3E%3C/svg%3E"); background-size: 40px 40px; pointer-events: none; }
.cd__pattern::after { content: ''; position: absolute; inset: 0; background: radial-gradient(ellipse 85% 70% at 50% 40%, #FAFAF8 30%, transparent 100%); }
.cd__wrap { position: relative; z-index: 2; max-width: 1280px; margin: 0 auto; }
.cd__head { text-align: center; margin-bottom: 60px; }
.cd__eyebrow { display: inline-flex; align-items: center; gap: 12px; font-family: 'Outfit', sans-serif; font-size: 0.7rem; font-weight: 600; letter-spacing: 0.25em; text-transform: uppercase; color: #C6973F; margin-bottom: 18px; }
.cd__eyebrow-line { width: 48px; height: 1px; background: #C6973F; }
.cd__title { font-family: 'Cormorant Garamond', serif; font-size: clamp(2.2rem, 4vw, 4rem); font-weight: 300; line-height: 1.1; color: #0C0C0C; letter-spacing: -0.01em; margin-bottom: 16px; display: flex; align-items: center; justify-content: center; gap: 12px; flex-wrap: wrap; }
.cd__title-icon { display: inline-flex; width: 32px; height: 32px; background: #C6973F; border-radius: 50%; align-items: center; justify-content: center; color: #fff; }
.cd__title em { font-style: italic; font-weight: 700; color: #C6973F; background: linear-gradient(120deg, #C6973F 0%, #E2BD6E 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
.cd__bar { width: 60px; height: 2px; background: linear-gradient(90deg, #C6973F, #E2BD6E); margin: 16px auto 18px; border-radius: 2px; }
.cd__subtitle { font-family: 'Outfit', sans-serif; font-size: 0.9rem; font-weight: 400; color: #666; max-width: 600px; margin: 0 auto; line-height: 1.7; display: flex; align-items: center; justify-content: center; gap: 8px; flex-wrap: wrap; }
.cd__subtitle svg { flex-shrink: 0; stroke: #C6973F; }
.cd__tabs { display: flex; flex-wrap: wrap; justify-content: center; gap: 12px; margin-bottom: 40px; }
.cd__tab-btn { padding: 10px 24px; border-radius: 30px; border: 1px solid rgba(201,168,76,0.3); background: transparent; color: #555; font-family: 'Outfit', sans-serif; font-size: 0.85rem; font-weight: 600; cursor: pointer; transition: all 0.25s ease; position: relative; }
.cd__tab-btn:hover { border-color: #C6973F; color: #0C0C0C; background: rgba(201,168,76,0.05); }
.cd__tab-btn.is-active { background: #C6973F; color: #fff; border-color: #C6973F; box-shadow: 0 4px 12px rgba(201,168,76,0.25); }
.cd__tab-badge { display: inline-block; font-size: 0.6rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; padding: 2px 8px; border-radius: 999px; margin-left: 6px; background: rgba(255,255,255,0.2); color: #fff; }
.cd__tab-badge--ongoing { background: rgba(16,185,129,0.3); color: #10b981; }
.cd__tab-badge--upcoming { background: rgba(201,168,76,0.3); color: #C6973F; }
.cd__panels { position: relative; }
.cd__panel { display: none; animation: cdFadeIn 0.4s ease; }
.cd__panel.is-active { display: block; }
@keyframes cdFadeIn { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
.cd__event-head { text-align: center; margin-bottom: 40px; }
.cd__event-head .cd__title { font-size: clamp(1.6rem, 3vw, 2.8rem); }
.cd__event-head .cd__subtitle { font-size: 0.85rem; }
.cd__event-badge { display: inline-block; margin-top: 6px; }
.cd__grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 24px; }
.cd__photo { position: relative; width: 100%; aspect-ratio: 2 / 2; background: #FAFAF8; overflow: hidden; }
.cd__photo img { width: 100%; height: 100%; object-fit: cover; transform: scale(1.0, 1.0); background-color: #FAFAF8; display: block; transition: transform 0.8s cubic-bezier(.25,.46,.45,.94), filter 0.4s; filter: brightness(0.97) saturate(0.95); }
.cd__card:hover .cd__photo img { filter: brightness(1) saturate(1.05); }
.cd__photo-veil { position: absolute; bottom: 0; left: 0; right: 0; height: 40%; background: linear-gradient(to top, rgba(0,0,0,0.55) 0%, transparent 100%); z-index: 1; }
.cd__num { position: absolute; top: 10px; left: 10px; z-index: 3; font-family: 'Outfit', sans-serif; font-size: 9px; font-weight: 700; letter-spacing: 0.1em; color: #0C0C0C; background: #C6973F; padding: 4px 9px; border-radius: 5px; box-shadow: 0 2px 8px rgba(201,168,76,0.4); }
.cd__photo-city { position: absolute; bottom: 10px; left: 10px; z-index: 3; display: flex; align-items: center; gap: 4px; font-family: 'Outfit', sans-serif; font-size: 0.65rem; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; color: rgba(255,255,255,0.9); }
.cd__tag { position: absolute; bottom: 10px; right: 10px; z-index: 3; font-family: 'Outfit', sans-serif; font-size: 0.6rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: #fff; background: rgba(123,26,47,0.85); padding: 3px 8px; border-radius: 4px; backdrop-filter: blur(6px); opacity: 0; transform: translateX(6px); transition: opacity 0.3s, transform 0.3s; }
.cd__card:hover .cd__tag { opacity: 1; transform: translateX(0); }
.cd__body { padding: 14px 14px 12px; }
.cd__name { font-family: 'Cormorant Garamond', serif; font-size: 1.25rem; font-weight: 700; color: #0C0C0C; letter-spacing: -0.005em; margin-bottom: 4px; line-height: 1.2; }
.cd__city-txt { display: flex; align-items: center; gap: 4px; font-family: 'Outfit', sans-serif; font-size: 0.7rem; font-weight: 400; color: #999; margin-bottom: 10px; }
.cd__divider { height: 1px; background: linear-gradient(90deg, #EEEBE3, transparent); margin-bottom: 10px; }
.cd__details { display: flex; flex-direction: column; gap: 6px; margin-bottom: 12px; }
.cd__detail-row { display: flex; align-items: center; gap: 6px; font-family: 'Outfit', sans-serif; font-size: 0.72rem; color: #666; }
.cd__detail-icon { width: 22px; height: 22px; border-radius: 6px; background: #F5F0E8; display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: #C6973F; }
.cd__detail-label { font-weight: 500; color: #333; }
.cd__stats { margin: 10px 0 6px; display: flex; flex-direction: column; gap: 10px; }
.cd__metrics { display: flex; justify-content: space-between; gap: 8px; }
.cd__metric-item { display: flex; align-items: center; gap: 4px; font-family: 'Outfit', sans-serif; font-size: 0.72rem; background: #FEF9EF; padding: 4px 10px; border-radius: 30px; border: 1px solid #F0E5D2; color: #5A4A2A; }
.cd__metric-item svg { stroke: #C6973F; width: 12px; height: 12px; }
.cd__metric-item strong { font-weight: 700; color: #C6973F; margin-left: 3px; }
.cd__score-wrap { margin-top: 2px; }
.cd__score-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; font-family: 'Outfit', sans-serif; font-size: 0.65rem; color: #aaa; letter-spacing: 0.02em; }
.cd__score-head strong { color: #C6973F; font-weight: 700; font-size: 0.7rem; }
.cd__score-track { height: 4px; background: #EDE9E0; border-radius: 2px; overflow: hidden; }
.cd__score-fill { height: 100%; border-radius: 2px; background: linear-gradient(90deg, #C6973F, #E2BD6E); width: 0%; transition: width 1.1s cubic-bezier(.4,0,.2,1) 0.3s; }
.cd__card.is-visible .cd__score-fill { width: var(--score, 0%); }
.cd__share-row { display: flex; align-items: center; gap: 6px; margin-bottom: 10px; }
.cd__share-btn { cursor: pointer; display: inline-flex; align-items: center; gap: 4px; background: #C6973F; color: #fff; border: none; border-radius: 20px; padding: 4px 12px; font-size: 0.6rem; font-weight: 600; text-decoration: none; transition: all 0.2s; }
.cd__share-btn:hover { background: #b5882a; }
.cd__footer { margin-top: 60px; text-align: center; }
.cd__footer-inner { display: inline-flex; flex-direction: column; align-items: center; gap: 12px; }
.cd__footer-deco { display: flex; align-items: center; gap: 14px; color: #C6973F; font-family: 'Outfit', sans-serif; font-size: 0.68rem; letter-spacing: 0.16em; text-transform: uppercase; }
.cd__footer-deco::before, .cd__footer-deco::after { content: ''; width: 60px; height: 1px; background: linear-gradient(90deg, transparent, #C6973F); }
.cd__footer-deco::after { background: linear-gradient(90deg, #C6973F, transparent); }
.cd__cta { display: inline-flex; align-items: center; gap: 10px; font-family: 'Outfit', sans-serif; font-size: 0.85rem; font-weight: 700; letter-spacing: 0.03em; color: #fff; background: #0C0C0C; padding: 14px 36px; border-radius: 10px; text-decoration: none; box-shadow: 0 6px 20px rgba(0,0,0,0.15); transition: background 0.25s, transform 0.25s, box-shadow 0.25s; }
.cd__cta:hover { background: #1A1A1A; transform: translateY(-2px); box-shadow: 0 10px 30px rgba(0,0,0,0.22); }
.cd__cta-arrow { transition: transform 0.25s; }
.cd__cta:hover .cd__cta-arrow { transform: translateX(4px); }
@media (min-width: 1400px) { .cd { padding: 90px 100px 100px; } }
@media (min-width: 1700px) { .cd { padding: 90px 160px 100px; } }
@media (max-width: 1400px) { .cd__grid { grid-template-columns: repeat(4, 1fr); gap: 20px; } }
@media (max-width: 1200px) { .cd { padding: 70px 28px 80px; } .cd__grid { grid-template-columns: repeat(3, 1fr); gap: 18px; } }
@media (max-width: 768px) { .cd { padding: 60px 16px 70px; } .cd__grid { grid-template-columns: repeat(2, 1fr) !important; gap: 12px !important; } .cd__head { margin-bottom: 30px; } .cd__body { padding: 10px 8px 12px !important; } .cd__name { font-size: 0.9rem !important; } .cd__city-txt { font-size: 0.6rem !important; margin-bottom: 6px !important; } .cd__detail-row { font-size: 0.6rem !important; gap: 4px !important; } .cd__detail-icon { width: 16px !important; height: 16px !important; } .cd__metrics .cd__metric-item { font-size: 0.6rem !important; padding: 2px 6px !important; } .cd__score-head { font-size: 0.55rem !important; } .cd__score-fill { height: 3px !important; } .cd__tag { display: none !important; } .cd__num { font-size: 7px !important; padding: 2px 6px !important; } }
@media (max-width: 420px) { .cd__grid { gap: 8px !important; } .cd__name { font-size: 0.75rem !important; } .cd__detail-row, .cd__metrics .cd__metric-item { font-size: 0.5rem !important; } .cd__body { padding: 6px 4px 8px !important; } .cd__detail-icon { width: 12px !important; height: 12px !important; } .cd__num { top: 6px !important; left: 6px !important; } }
body { font-family: 'Outfit', sans-serif; background: #080808; color: #fff; margin: 0; }
</style>
</head>
<body>
<section class="cd" id="candidates" aria-labelledby="cd-title">
    <div class="cd__pattern" aria-hidden="true"></div>
    <div class="cd__wrap">
        <div class="cd__head">
            <div class="cd__eyebrow"><span class="cd__eyebrow-line"></span> Édition officielle <?= date('Y') ?> <span class="cd__eyebrow-line"></span></div>
            <h2 class="cd__title" id="cd-title"><span class="cd__title-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg></span>Candidates du concours</h2>
            <div class="cd__bar"></div>
            <p class="cd__subtitle"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>Découvrez les candidates par étape du concours</p>
        </div>
        <?php if (count($etapes) > 0): ?>
        <div class="cd__tabs" role="tablist" aria-label="Étapes du concours">
            <?php foreach ($etapes as $idx => $etape):
                $debut = strtotime($etape['date_ouverture']); $fin = strtotime($etape['date_cloture']); $nowTs = time();
                if ($nowTs >= $debut && $nowTs <= $fin) { $statusLabel = 'En cours'; $badgeClass = 'cd__tab-badge--ongoing'; }
                elseif ($nowTs < $debut) { $statusLabel = 'À venir'; $badgeClass = 'cd__tab-badge--upcoming'; }
                else { $statusLabel = 'Terminé'; $badgeClass = ''; }
            ?>
            <button class="cd__tab-btn <?= $idx === 0 ? 'is-active' : '' ?>" role="tab" aria-selected="<?= $idx === 0 ? 'true' : 'false' ?>" aria-controls="panel-<?= $etape['etape_id'] ?>" id="tab-<?= $etape['etape_id'] ?>" data-panel="panel-<?= $etape['etape_id'] ?>">
                <?= esc($etape['nom_etape'] ?? 'Étape ' . $etape['numero_ordre']) ?>
                <?php if ($statusLabel): ?><span class="cd__tab-badge <?= $badgeClass ?>"><?= $statusLabel ?></span><?php endif; ?>
            </button>
            <?php endforeach; ?>
        </div>
        <div class="cd__panels">
            <?php foreach ($etapes as $idx => $etape):
                $panelId = 'panel-' . $etape['etape_id']; $isActive = ($idx === 0);
                $candidates = $candidatesByEtape[$etape['etape_id']] ?? [];
            ?>
            <div class="cd__panel <?= $isActive ? 'is-active' : '' ?>" id="<?= $panelId ?>" role="tabpanel" aria-labelledby="tab-<?= $etape['etape_id'] ?>">
                <div class="cd__event-head">
                    <h3 class="cd__title" style="font-size: clamp(1.6rem, 3vw, 2.8rem);"><?= esc($etape['nom_etape'] ?? 'Étape ' . $etape['numero_ordre']) ?></h3>
                    <?php if (!empty($etape['description_etape'])): ?><p class="cd__subtitle"><?= esc($etape['description_etape']) ?></p><?php endif; ?>
                    <div class="cd__event-badge"><span style="display:inline-block;font-size:0.65rem;font-weight:600;letter-spacing:0.1em;text-transform:uppercase;color:#999;background:#f0f0f0;padding:3px 14px;border-radius:30px;"><?= date('d/m/Y', strtotime($etape['date_ouverture'])) ?> → <?= date('d/m/Y', strtotime($etape['date_cloture'])) ?></span></div>
                </div>
                <?php if (count($candidates) > 0): ?>
                <div class="cd__grid">
                    <?php foreach ($candidates as $cand):
                        $votes = $cand['total_votes'];
                        $pct = $totalVotesAll > 0 ? round(($votes / $totalVotesAll) * 100, 1) : 0;
                        $photoUrl = getCandidatePhotoUrl($cand['photo_officielle'] ?? '');
                    ?>
                    <div class="cd__card">
                        <div class="cd__photo">
                            <img src="<?= esc($photoUrl ?: 'https://images.unsplash.com/photo-1529626455594-4ff0802cfb7e?w=400&fit=crop') ?>?v=<?= time() ?>" alt="<?= esc($cand['nom_complet']) ?>" loading="lazy">
                            <div class="cd__photo-veil"></div>
                            <span class="cd__num">N° <?= esc($cand['code_participante']) ?></span>
                            <p class="cd__photo-city"><svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 10c0 6-8 12-8 12s-8-6-8-10a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg><?= esc($cand['ville_origine'] ?? 'Kinshasa') ?></p>
                            <span class="cd__tag">Candidate <?= date('Y') ?></span>
                        </div>
                        <div class="cd__body">
                            <h3 class="cd__name"><?= esc($cand['nom_complet']) ?></h3>
                            <div class="cd__share-row"><button class="cd__share-btn" onclick="copyVoteLink(this)"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>Copier le lien de vote</button></div>
                            <div class="cd__divider"></div>
                            <div class="cd__details">
                                <div class="cd__detail-row"><span class="cd__detail-icon"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="20" height="14" x="2" y="7" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg></span><span class="cd__detail-label">Code: <?= esc($cand['code_participante']) ?></span></div>
                                <div class="cd__detail-row"><span class="cd__detail-icon"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/></svg></span><span class="cd__detail-label">Niveau: <?= esc($cand['niveau_etudes'] ?? 'Non précisé') ?></span></div>
                            </div>
                            <div class="cd__stats">
                                <div class="cd__metrics"><div class="cd__metric-item"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg><span><?= $votes ?> <strong>votes</strong></span></div></div>
                                <div class="cd__score-wrap"><div class="cd__score-head"><span>Popularité</span><strong><?= $pct ?>%</strong></div><div class="cd__score-track"><div class="cd__score-fill" style="--score:<?= $pct ?>%"></div></div></div>
                            </div>
                            <div style="display:flex;gap:8px;margin-top:8px;">
                                <a href="profil.php?code=<?= urlencode($cand['code_participante']) ?>" style="flex:1;text-align:center;padding:6px 0;border-radius:6px;font-size:0.7rem;font-weight:600;text-transform:uppercase;background:rgba(0,0,0,.04);border:1px solid #ddd;color:#333;text-decoration:none;">Profil</a>
                                <a href="voter.php?candidat=<?= urlencode($cand['participante_id']) ?>&concours_id=<?= $concoursId ?>&etape_id=<?= $etape['etape_id'] ?>" style="flex:1;text-align:center;padding:6px 0;border-radius:6px;font-size:0.7rem;font-weight:700;text-transform:uppercase;background:#C6973F;color:#fff;border:none;text-decoration:none;">Voter</a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?><p style="text-align:center;color:#999;">Aucune candidate inscrite pour cette étape.</p><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?><p style="text-align:center;color:#999;">Aucune étape disponible pour ce concours.</p><?php endif; ?>
        <div class="cd__footer">
            <div class="cd__footer-inner">
                <div class="cd__footer-deco"><?= count($allCandidates) ?> candidate(s)</div>
                <a href="#candidates" class="cd__cta">Voir toutes les candidates <svg class="cd__cta-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></a>
            </div>
        </div>
    </div>
</section>
<script>
function copyVoteLink(btn) {
    const card = btn.closest('.cd__card');
    const voteLink = card.querySelector('a[href*="voter.php"]');
    if (voteLink) {
        const url = voteLink.href;
        if (navigator.clipboard) {
            navigator.clipboard.writeText(url).then(() => {
                const origHTML = btn.innerHTML; const origBg = btn.style.background;
                btn.innerHTML = '✅ Copié !'; btn.style.background = '#22c55e'; btn.style.color = '#fff';
                setTimeout(() => { btn.innerHTML = origHTML; btn.style.background = origBg; btn.style.color = '#fff'; }, 2000);
            });
        } else {
            const textArea = document.createElement('textarea'); textArea.value = url;
            document.body.appendChild(textArea); textArea.select(); document.execCommand('copy'); document.body.removeChild(textArea);
            const origHTML = btn.innerHTML; const origBg = btn.style.background;
            btn.innerHTML = '✅ Copié !'; btn.style.background = '#22c55e'; btn.style.color = '#fff';
            setTimeout(() => { btn.innerHTML = origHTML; btn.style.background = origBg; btn.style.color = '#C6973F'; }, 2000);
        }
    }
}

document.querySelectorAll('.cd__tab-btn').forEach(tab => {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.cd__tab-btn').forEach(t => { t.classList.remove('is-active'); t.setAttribute('aria-selected', 'false'); });
        this.classList.add('is-active'); this.setAttribute('aria-selected', 'true');
        document.querySelectorAll('.cd__panel').forEach(p => p.classList.remove('is-active'));
        const panel = document.getElementById(this.getAttribute('data-panel'));
        if (panel) panel.classList.add('is-active');
    });
});

const reveals = document.querySelectorAll('.cd__card');
const io = new IntersectionObserver(entries => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            const idx = Array.from(entry.target.parentElement.children).indexOf(entry.target);
            setTimeout(() => entry.target.classList.add('is-visible'), idx * 100);
            io.unobserve(entry.target);
        }
    });
}, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });
reveals.forEach(r => io.observe(r));
</script>
</body>
</html>
