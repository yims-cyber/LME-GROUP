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
} catch (PDOException $e) { die("Erreur de connexion : " . $e->getMessage()); }

$host = $_SERVER['HTTP_HOST'] ?? '';
$domain = 'zaloriatech.com';
$subdomain = '';
if (preg_match('/^(.*?)\.' . preg_quote($domain, '/') . '$/', $host, $matches)) { $subdomain = $matches[1]; }
else { $subdomain = 'gestion'; }

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
            if (strlen($tel) >= 8) { $vote['telephone_masked'] = substr($tel, 0, 4) . '****' . substr($tel, -2); }
            else { $vote['telephone_masked'] = '****'; }
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
<title><?= esc($siteName) ?> — Ticker</title>
<style>
/* ═══════ TICKER ═══════ */
.ticker-section { background: #0D0D0D; border-top: 1px solid var(--gold-border); border-bottom: 1px solid var(--gold-border); padding: 12px 0; overflow: hidden; position: relative; }
.ticker-container { max-width: 1280px; margin: 0 auto; padding: 0 20px; display: flex; flex-direction: column; gap: 6px; }
.ticker-row { display: flex; align-items: center; gap: 16px; overflow: hidden; white-space: nowrap; position: relative; height: 28px; line-height: 28px; font-size: 0.8rem; color: var(--muted); }
.ticker-label { background: #0d0d0d;z-index: 999; flex-shrink: 0; font-weight: 700; color: var(--gold); text-transform: uppercase; letter-spacing: 0.1em; font-size: 0.65rem; margin-right: 4px; }
.ticker-track { display: inline-block; animation: scroll 35s linear infinite; padding-left: 100%; white-space: nowrap; }
.ticker-track:hover { animation-play-state: paused; }
@keyframes scroll { 0% { transform: translateX(0); } 100% { transform: translateX(-100%); } }
.ticker-item { display: inline-block; padding-right: 40px; }
.ticker-item .highlight { color: #fff; font-weight: 600; }
.ticker-item .gold-text { color: var(--gold-light); font-weight: 600; }
.ticker-item .votes-count { color: var(--gold-light); font-weight: 700; }
.ticker-item .separator { color: var(--gold); margin: 0 12px; opacity: 0.5; }
@media (max-width: 768px) { .ticker-container { padding: 0 20px; } .ticker-row { height: 24px; line-height: 24px; font-size: 0.7rem; } .ticker-item { padding-right: 20px; } }

:root { --gold: #C9A84C; --gold-light: #E8CC80; --gold-border: rgba(201,168,76,.32); --black: #080808; --muted: rgba(255,255,255,.46); }
body { font-family: 'Outfit', sans-serif; background: var(--black); color: #fff; margin: 0; }
</style>
</head>
<body>
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
<script>
function loadLiveStats() {
    const urlParams = new URLSearchParams(window.location.search);
    const concoursId = urlParams.get('concours_id') || <?= json_encode($concoursId) ?>;
    fetch('?ajax=votes_data&concours_id=' + encodeURIComponent(concoursId))
        .then(response => response.json())
        .then(data => {
            let rankingText = '';
            if (data.ranking.length === 0) { rankingText = 'Aucun vote pour le moment.'; }
            else {
                const items = data.ranking.map((cand, index) => {
                    const rank = index + 1;
                    const medal = rank === 1 ? '🥇' : rank === 2 ? '🥈' : rank === 3 ? '🥉' : `#${rank}`;
                    return `${medal} ${cand.nom_complet} ${cand.total_votes} votes`;
                });
                rankingText = items.join(' <span class="separator">◆</span> ');
            }
            document.getElementById('ticker-ranking').innerHTML = `<span class="ticker-item">${rankingText}</span>`;
            let votesText = '';
            if (data.latestVotes.length === 0) { votesText = 'Aucun vote récent.'; }
            else {
                const items = data.latestVotes.map(vote => {
                    return `${vote.nom_complet} +${vote.votes_accordes} ${vote.telephone_masked} ${vote.date_fr}`;
                });
                votesText = items.join(' <span class="separator">◆</span> ');
            }
            document.getElementById('ticker-votes').innerHTML = `<span class="ticker-item">${votesText}</span>`;
        })
        .catch(error => console.error('Erreur ticker:', error));
}
loadLiveStats();
setInterval(loadLiveStats, 10000);
</script>
</body>
</html>
