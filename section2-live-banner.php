<?php
function esc($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

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
if (preg_match('/^(.*?)\.' . preg_quote($domain, '/') . '$/', $host, $matches)) {
    $subdomain = $matches[1];
} else {
    $subdomain = 'gestion';
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
foreach ($concoursList as $c) {
    if ($c['concours_id'] === $selectedConcoursId) { $currentConcours = $c; break; }
}
if (!$currentConcours && !empty($concoursList)) $currentConcours = $concoursList[0];
$concoursId = $currentConcours ? $currentConcours['concours_id'] : 0;

$etapes = [];
if ($concoursId > 0) {
    $stmtEtapes = $pdo->prepare("SELECT e.etape_id, e.concours_id, e.type_etape_id, e.numero_ordre, e.date_ouverture, e.date_cloture, e.votes_actifs, e.etape_terminee, e.cree_le, e.modifie_le, t.nom_etape, t.description_etape FROM etapes_du_concours e JOIN types_etapes t ON e.type_etape_id = t.type_etape_id WHERE e.concours_id = ? AND e.etape_terminee = 0 ORDER BY e.numero_ordre ASC");
    $stmtEtapes->execute([$concoursId]);
    $etapes = $stmtEtapes->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= esc($siteName) ?> — Bannière Live</title>
<style>
/* ═══════ LIVE BANNER ═══════ */
.vb { position: relative; overflow: hidden; background: #0A0A0A; border-bottom: 1px solid rgba(201,168,76,.12); }
.vb::before { content: ''; position: absolute; inset: 0; background: linear-gradient(90deg, transparent 0%, rgba(201,168,76,.04) 30%, rgba(201,168,76,.07) 50%, rgba(201,168,76,.04) 70%, transparent 100%); pointer-events: none; }
.vb__inner { position: relative; z-index: 2; max-width: 1280px; margin: 0 auto; padding: 14px 60px; display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap; }
.vb__events { flex: 1; display: flex; gap: 24px; overflow-x: auto; padding: 4px 0 8px; scroll-behavior: smooth; -webkit-overflow-scrolling: touch; scrollbar-width: thin; scrollbar-color: rgba(201,168,76,.3) transparent; }
.vb__events::-webkit-scrollbar { height: 3px; }
.vb__events::-webkit-scrollbar-track { background: transparent; }
.vb__events::-webkit-scrollbar-thumb { background: rgba(201,168,76,.3); border-radius: 4px; }
.vb__event-item { flex: 0 0 auto; min-width: 240px; max-width: 320px; display: flex; flex-direction: column; gap: 6px; background: rgba(255,255,255,.03); border-radius: 8px; padding: 12px 16px; border: 1px solid rgba(255,255,255,.05); }
.vb__event-item--ongoing { border-color: rgba(16,185,129,.25); background: rgba(16,185,129,.05); }
.vb__event-item--upcoming { border-color: rgba(201,168,76,.15); background: rgba(201,168,76,.04); }
.vb__event-header { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.vb__event-name { font-family: 'Outfit', sans-serif; font-size: .85rem; font-weight: 600; color: rgba(255,255,255,.85); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.vb__event-badge { font-family: 'Outfit', sans-serif; font-size: .6rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; padding: 2px 10px; border-radius: 999px; background: rgba(255,255,255,.06); color: #aaa; flex-shrink: 0; }
.vb__event-badge--ongoing { background: rgba(16,185,129,.15); color: #34d399; }
.vb__event-badge--upcoming { background: rgba(201,168,76,.15); color: #C6973F; }
.vb__event-dates { font-family: 'Outfit', sans-serif; font-size: .7rem; color: rgba(255,255,255,.4); display: flex; align-items: center; gap: 6px; }
.vb__event-progress { display: flex; flex-direction: column; gap: 3px; margin-top: 2px; }
.vb__event-progress-track { width: 100%; height: 3px; background: rgba(255,255,255,.08); border-radius: 2px; overflow: hidden; }
.vb__event-progress-fill { height: 100%; width: 0%; background: linear-gradient(90deg, #C6973F, #E2BD6E); border-radius: 2px; transition: width 0.8s cubic-bezier(.4,0,.2,1); }
.vb__event-progress-label { font-family: 'Outfit', sans-serif; font-size: .6rem; color: rgba(255,255,255,.3); letter-spacing: .04em; }
.vb__cta { display: inline-flex; align-items: center; gap: 10px; padding: 10px 24px; border-radius: 8px; background: #C6973F; color: #080808; font-family: 'Outfit', sans-serif; font-size: .875rem; font-weight: 700; letter-spacing: .03em; text-decoration: none; white-space: nowrap; flex-shrink: 0; transition: background .22s ease, transform .22s ease, box-shadow .22s ease; box-shadow: 0 4px 16px rgba(201,168,76,.25); }
.vb__cta:hover { background: var(--gold-light); transform: translateY(-1px); box-shadow: 0 8px 24px rgba(201,168,76,.4); }
@media (max-width: 960px) { .vb__inner { padding: 14px 28px; flex-direction: column; align-items: stretch; gap: 14px; } .vb__event-item { min-width: 200px; } }
@media (max-width: 480px) { .vb__inner { padding: 14px 18px; } .vb__event-item { min-width: 160px; padding: 10px 12px; } }
@media (min-width: 1400px) { .vb__inner { padding: 16px 100px; } }
@media (min-width: 1700px) { .vb__inner { padding: 16px 160px; } }

body { font-family: 'Outfit', sans-serif; background: #080808; color: #fff; margin: 0; }
</style>
</head>
<body>
<div class="vb" role="banner" aria-label="Bannière des événements">
    <div class="vb__inner">
        <div class="vb__events" id="vbEvents">
            <?php foreach ($etapes as $ev):
                $debut = strtotime($ev['date_ouverture'] ?? 'now');
                $fin = strtotime($ev['date_cloture'] ?? 'now');
                $nowTs = time();
                if ($nowTs >= $debut && $nowTs <= $fin) {
                    $statusClass = 'ongoing'; $label = 'En cours';
                    $progress = ($fin - $debut) > 0 ? min(100, round((($nowTs - $debut) / ($fin - $debut)) * 100)) : 0;
                } else if ($nowTs < $debut) {
                    $statusClass = 'upcoming'; $label = 'À venir'; $progress = 0;
                } else {
                    $statusClass = 'upcoming'; $label = 'Terminé'; $progress = 100;
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
</body>
</html>
