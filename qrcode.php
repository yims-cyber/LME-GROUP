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

if (preg_match('/^(.*?)\.' . preg_quote($domain, '/') . '$/', $host, $matches)) {
    $subdomain = $matches[1];
} else {
    $subdomain = 'gestion';
}

$stmtSite = $pdo->prepare("SELECT site_id, nom_entreprise, logo_concours, logo_extension, lien_unique FROM sites WHERE lien_unique = ?");
$stmtSite->execute([$subdomain]);
$siteData = $stmtSite->fetch();

if (!$siteData) {
    $stmtFallback = $pdo->query("SELECT site_id, nom_entreprise, logo_concours, logo_extension, lien_unique FROM sites LIMIT 1");
    $siteData = $stmtFallback->fetch();
    if (!$siteData) {
        die("Aucun site trouvé.");
    }
    $subdomain = $siteData['lien_unique'];
}

$siteId = $siteData['site_id'];
$siteName = $siteData['nom_entreprise'];

define('STOCKAGE_DOMAIN', 'https://gestion.zaloriatech.com');
define('SITE_URL', 'https://' . $subdomain . '.zaloriatech.com');

$siteLogoUrl = '';
if ($siteData['logo_concours'] && !empty($siteData['logo_extension'])) {
    $siteLogoUrl = STOCKAGE_DOMAIN . '/admin/uploads/sites_logo/' . $siteData['lien_unique'] . '.' . $siteData['logo_extension'] . '?v=' . time();
}
// Version data-URI (base64) du logo, pour contourner le blocage CORS sur le canvas
$siteLogoDataUri = toDataUri($siteLogoUrl);

// ========== Concours sélectionné ==========
$stmtConcours = $pdo->prepare("
    SELECT concours_id, nom_concours, url_concours, date_ouverture, date_cloture, etat_concours, arret_manuel
    FROM concours
    WHERE site_id = ? AND etat_concours = 'actif' AND arret_manuel = 0
      AND NOW() BETWEEN date_ouverture AND date_cloture
    ORDER BY date_ouverture ASC
");
$stmtConcours->execute([$siteId]);
$concoursList = $stmtConcours->fetchAll();

if (empty($concoursList)) {
    $stmtConcoursFallback = $pdo->prepare("
        SELECT concours_id, nom_concours, url_concours, date_ouverture, date_cloture, etat_concours, arret_manuel
        FROM concours WHERE site_id = ? ORDER BY date_ouverture ASC LIMIT 1
    ");
    $stmtConcoursFallback->execute([$siteId]);
    $concoursList = $stmtConcoursFallback->fetchAll();
}

$selectedConcoursId = isset($_GET['concours_id']) ? (int)$_GET['concours_id'] : 0;
$currentConcours = null;
foreach ($concoursList as $c) {
    if ($c['concours_id'] === $selectedConcoursId) { $currentConcours = $c; break; }
}
if (!$currentConcours && !empty($concoursList)) {
    $currentConcours = $concoursList[0];
}
$concoursId = $currentConcours ? $currentConcours['concours_id'] : 0;
$concoursNom = $currentConcours['nom_concours'] ?? $siteName;

// ========== Étapes non terminées ==========
$etapes = [];
if ($concoursId > 0) {
    $stmtEtapes = $pdo->prepare("
        SELECT e.etape_id, e.numero_ordre, t.nom_etape
        FROM etapes_du_concours e
        JOIN types_etapes t ON e.type_etape_id = t.type_etape_id
        WHERE e.concours_id = ? AND e.etape_terminee = 0
        ORDER BY e.numero_ordre ASC
    ");
    $stmtEtapes->execute([$concoursId]);
    $etapes = $stmtEtapes->fetchAll();
}
// étape par défaut pour les liens de vote candidate (première étape active)
$defaultEtapeId = $etapes[0]['etape_id'] ?? 0;

// ========== Candidates actives (rattachées à une étape non terminée) ==========
$candidates = [];
if ($concoursId > 0) {
    $stmtPart = $pdo->prepare("
        SELECT p.participante_id, p.code_participante, p.nom_complet, p.ville_origine,
               (SELECT m.photo_officielle
                FROM medias_participantes m
                WHERE m.participante_id = p.participante_id
                ORDER BY m.est_photo_principale DESC, m.ajoute_le DESC
                LIMIT 1) AS photo_officielle,
               pp.etape_id
        FROM participantes p
        JOIN parcours_participantes pp ON p.participante_id = pp.participante_id
        JOIN etapes_du_concours e ON pp.etape_id = e.etape_id
        WHERE p.concours_id = ? AND p.situation_actuelle = 'active' AND e.etape_terminee = 0
        GROUP BY p.participante_id
        ORDER BY p.code_participante ASC
    ");
    $stmtPart->execute([$concoursId]);
    $candidates = $stmtPart->fetchAll();
}

function esc($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

function buildPhotoUrl($photoOfficielle) {
    if (empty($photoOfficielle)) return '';
    $path = ltrim($photoOfficielle, '/');
    if (strpos($path, 'admin/') !== 0) {
        $path = 'admin/' . $path;
    }
    return STOCKAGE_DOMAIN . '/' . $path;
}

/**
 * Récupère une image distante côté serveur (pas de restriction CORS en PHP)
 * et la convertit en data URI base64, utilisable directement dans un <canvas>
 * sans blocage cross-origin. Retourne '' en cas d'échec.
 */
function toDataUri($url) {
    if (empty($url)) return '';
    static $cache = [];
    if (isset($cache[$url])) return $cache[$url];

    $data = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) $data = false;
    }
    if ($data === false) {
        $ctx = stream_context_create(['http' => ['timeout' => 8], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $data = @file_get_contents($url, false, $ctx);
    }
    if ($data === false || $data === '') {
        $cache[$url] = '';
        return '';
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->buffer($data) ?: 'image/jpeg';
    $uri = 'data:' . $mime . ';base64,' . base64_encode($data);
    $cache[$url] = $uri;
    return $uri;
}

// Payload JS pour les candidates
$candidatesPayload = [];
foreach ($candidates as $cand) {
    $voteUrl = SITE_URL . '/voter.php?candidat=' . urlencode($cand['participante_id']) . '&concours_id=' . $concoursId . '&etape_id=' . $cand['etape_id'];
    $photoUrl = buildPhotoUrl($cand['photo_officielle']);
    $candidatesPayload[] = [
        'id' => $cand['participante_id'],
        'code' => $cand['code_participante'],
        'nom' => $cand['nom_complet'],
        'ville' => $cand['ville_origine'] ?: 'Kinshasa',
        'photo' => toDataUri($photoUrl), // data URI => évite le blocage CORS sur le canvas
        'url' => $voteUrl,
    ];
}

$siteVoteAllUrl = SITE_URL . '/index.php#candidates';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>QR Codes — <?= esc($siteName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,700;1,300;1,700&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
  :root{
    --gold:#C6973F; --gold-lt:#E4C078; --ivory:#FAF6ED; --charcoal:#1a1613;
    --terracotta:#B5583A; --muted:#8a8378;
  }
  *{box-sizing:border-box;margin:0;padding:0;}
  body{
    font-family:'Outfit',sans-serif;
    background:linear-gradient(180deg,#141110 0%, #1a1613 100%);
    color:var(--ivory);
    min-height:100vh;
    padding:40px 20px 80px;
  }
  .wrap{max-width:1280px;margin:0 auto;}
  .page-head{text-align:center;margin-bottom:48px;}
  .eyebrow{
    font-size:.65rem;font-weight:600;letter-spacing:.28em;text-transform:uppercase;
    color:var(--gold);display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:14px;
  }
  .eyebrow::before,.eyebrow::after{content:'';width:34px;height:1px;background:var(--gold);opacity:.5;}
  h1{
    font-family:'Cormorant Garamond',serif;font-weight:300;font-size:clamp(2rem,4.5vw,3.4rem);
    letter-spacing:-.01em;
  }
  h1 em{font-style:italic;font-weight:700;color:var(--gold-lt);}
  .bar{width:44px;height:2px;background:linear-gradient(90deg,var(--gold),var(--gold-lt));margin:16px auto 0;border-radius:2px;}
  .sub{color:var(--muted);font-size:.9rem;margin-top:16px;max-width:560px;margin-left:auto;margin-right:auto;}

  .concours-select{
    text-align:center;margin-bottom:44px;
  }
  .concours-select select{
    background:rgba(255,255,255,.05);border:1px solid rgba(198,151,63,.35);color:var(--ivory);
    padding:10px 18px;border-radius:8px;font-family:'Outfit',sans-serif;font-size:.85rem;
  }

  section.block{margin-bottom:64px;}
  .block-title{
    font-family:'Cormorant Garamond',serif;font-size:1.6rem;font-weight:700;color:var(--gold-lt);
    margin-bottom:22px;display:flex;align-items:center;gap:10px;
  }
  .block-title::before{content:'';width:6px;height:6px;background:var(--gold);border-radius:50%;}

  .hero-cards{
    display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:24px;
  }
  .qcard{
    background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:16px;
    padding:26px;text-align:center;transition:border-color .2s, transform .2s;
  }
  .qcard:hover{border-color:rgba(198,151,63,.5);transform:translateY(-3px);}
  .qcard-canvas-wrap{
    background:#fff;border-radius:12px;padding:14px;display:inline-block;margin-bottom:16px;
    box-shadow:0 8px 30px rgba(0,0,0,.35);
  }
  .qcard-photo-wrap{width:120px;height:120px;border-radius:50%;overflow:hidden;margin:0 auto 14px;border:3px solid var(--gold);background:#000;}
  .qcard-photo-wrap img{width:100%;height:100%;object-fit:cover;display:block;}
  .qcard h3{font-family:'Cormorant Garamond',serif;font-size:1.25rem;font-weight:700;margin-bottom:4px;color:#fff;}
  .qcard .meta{font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:16px;}
  .qcard .code{color:var(--gold);font-weight:600;}
  .dl-btn{
    display:inline-flex;align-items:center;gap:8px;background:var(--gold);color:#1a1613;
    font-weight:700;font-size:.72rem;letter-spacing:.05em;text-transform:uppercase;
    padding:10px 20px;border-radius:8px;border:none;cursor:pointer;text-decoration:none;
    transition:background .2s;
  }
  .dl-btn:hover{background:var(--gold-lt);}
  .dl-all{
    display:block;margin:0 auto 40px;text-align:center;
  }
  .dl-all button{
    background:transparent;border:1px solid var(--gold);color:var(--gold-lt);
    padding:12px 28px;border-radius:8px;font-family:'Outfit',sans-serif;font-weight:600;
    font-size:.78rem;letter-spacing:.08em;text-transform:uppercase;cursor:pointer;transition:all .2s;
  }
  .dl-all button:hover{background:var(--gold);color:#1a1613;}

  .candidates-grid{
    display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:22px;
  }
  .empty-msg{text-align:center;color:var(--muted);padding:40px 0;font-style:italic;}

  canvas{display:block;}
</style>
</head>
<body>

<div class="wrap">
  <div class="page-head">
    <div class="eyebrow">Impression &amp; Partage</div>
    <h1>QR Codes — <em><?= esc($siteName) ?></em></h1>
    <div class="bar"></div>
    <p class="sub">Scannez ou téléchargez les codes QR du site, du vote général et de chaque candidate pour <?= esc($concoursNom) ?>.</p>
  </div>

  <?php if (count($concoursList) > 1): ?>
  <div class="concours-select">
    <form method="get">
      <select name="concours_id" onchange="this.form.submit()">
        <?php foreach ($concoursList as $c): ?>
          <option value="<?= $c['concours_id'] ?>" <?= $c['concours_id'] === $concoursId ? 'selected' : '' ?>><?= esc($c['nom_concours']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
  <?php endif; ?>

  <!-- ═══ SITE + VOTE GLOBAL ═══ -->
  <section class="block">
    <div class="block-title">QR Codes Principaux</div>
    <div class="hero-cards">
      <div class="qcard">
        <div class="qcard-canvas-wrap"><div id="qr-site"></div></div>
        <h3>Site officiel</h3>
        <div class="meta"><?= esc(SITE_URL) ?></div>
        <button class="dl-btn" onclick="downloadQR('qr-site','qrcode-site-<?= esc($subdomain) ?>')">
          ⬇ Télécharger PNG
        </button>
      </div>
      <div class="qcard">
        <div class="qcard-canvas-wrap"><div id="qr-vote-all"></div></div>
        <h3>Vote — Toutes les candidates</h3>
        <div class="meta">Accès direct à la section vote</div>
        <button class="dl-btn" onclick="downloadQR('qr-vote-all','qrcode-vote-general-<?= esc($subdomain) ?>')">
          ⬇ Télécharger PNG
        </button>
      </div>
    </div>
  </section>

  <!-- ═══ CANDIDATES ═══ -->
  <section class="block">
    <div class="block-title">QR Codes de vote — Candidates (<?= count($candidates) ?>)</div>
    <?php if (count($candidates) > 0): ?>
    <div class="dl-all">
      <button onclick="downloadAllCandidates()">⬇ Télécharger tous les QR codes (ZIP simulé — un par un)</button>
    </div>
    <div class="candidates-grid" id="candidatesGrid"></div>
    <?php else: ?>
    <p class="empty-msg">Aucune candidate active pour ce concours actuellement.</p>
    <?php endif; ?>
  </section>
</div>

<script>
const SITE_LOGO_URL = <?= json_encode($siteLogoDataUri) ?>;
const SITE_URL_STR = <?= json_encode(SITE_URL) ?>;
const VOTE_ALL_URL = <?= json_encode($siteVoteAllUrl) ?>;
const CANDIDATES = <?= json_encode($candidatesPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

/**
 * Génère un QR code dans le conteneur donné, puis superpose une image
 * circulaire (logo ou photo) au centre, sur un canvas final.
 */
function makeQrWithCenterImage(containerId, text, centerImgUrl, size = 260) {
  return new Promise((resolve) => {
    const container = document.getElementById(containerId);
    container.innerHTML = '';

    // Génération QR haute correction d'erreur (nécessaire pour superposer une image)
    new QRCode(container, {
      text: text,
      width: size,
      height: size,
      correctLevel: QRCode.CorrectLevel.H
    });

    // Laisser le temps au canvas/qrcode.js de se générer
    setTimeout(() => {
      const qrCanvas = container.querySelector('canvas');
      const qrImg = container.querySelector('img');

      const finalCanvas = document.createElement('canvas');
      finalCanvas.width = size;
      finalCanvas.height = size;
      const ctx = finalCanvas.getContext('2d');
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, size, size);

      const drawBase = () => {
        if (qrCanvas) {
          ctx.drawImage(qrCanvas, 0, 0, size, size);
        } else if (qrImg) {
          ctx.drawImage(qrImg, 0, 0, size, size);
        }
        if (centerImgUrl) {
          drawCenterImage();
        } else {
          finalize();
        }
      };

      const drawCenterImage = () => {
        const img = new Image();
        img.onload = () => {
          const imgSize = size * 0.26;
          const cx = (size - imgSize) / 2;
          const cy = (size - imgSize) / 2;
          const pad = imgSize * 0.12;

          // fond blanc arrondi derrière l'image centrale
          ctx.save();
          roundRect(ctx, cx - pad, cy - pad, imgSize + pad * 2, imgSize + pad * 2, 12);
          ctx.fillStyle = '#ffffff';
          ctx.fill();
          ctx.restore();

          // image (cercle) au centre
          ctx.save();
          ctx.beginPath();
          ctx.arc(size / 2, size / 2, imgSize / 2, 0, Math.PI * 2);
          ctx.closePath();
          ctx.clip();
          ctx.drawImage(img, cx, cy, imgSize, imgSize);
          ctx.restore();

          finalize();
        };
        img.onerror = finalize; // si l'image ne charge pas, on garde le QR seul
        img.src = centerImgUrl;
      };

      const finalize = () => {
        container.innerHTML = '';
        container.appendChild(finalCanvas);
        resolve(finalCanvas);
      };

      drawBase();
    }, 120);
  });
}

function roundRect(ctx, x, y, w, h, r) {
  ctx.beginPath();
  ctx.moveTo(x + r, y);
  ctx.arcTo(x + w, y, x + w, y + h, r);
  ctx.arcTo(x + w, y + h, x, y + h, r);
  ctx.arcTo(x, y + h, x, y, r);
  ctx.arcTo(x, y, x + w, y, r);
  ctx.closePath();
}

function downloadQR(containerId, filename) {
  const container = document.getElementById(containerId);
  const canvas = container.querySelector('canvas');
  if (!canvas) return;
  const link = document.createElement('a');
  link.download = filename + '.png';
  link.href = canvas.toDataURL('image/png');
  link.click();
}

function buildCandidateCard(cand) {
  const containerId = 'qr-cand-' + cand.id;
  const card = document.createElement('div');
  card.className = 'qcard';
  card.innerHTML = `
    <div class="qcard-canvas-wrap"><div id="${containerId}"></div></div>
    <h3>${escapeHtml(cand.nom)}</h3>
    <div class="meta">N° <span class="code">${escapeHtml(cand.code)}</span> · ${escapeHtml(cand.ville)}</div>
    <button class="dl-btn" onclick="downloadQR('${containerId}','qrcode-vote-${escapeAttr(cand.code)}')">⬇ Télécharger PNG</button>
  `;
  document.getElementById('candidatesGrid').appendChild(card);
  return containerId;
}

function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}
function escapeAttr(s) {
  return String(s ?? '').replace(/[^a-zA-Z0-9_-]/g, '');
}

async function downloadAllCandidates() {
  for (const cand of CANDIDATES) {
    const containerId = 'qr-cand-' + cand.id;
    downloadQR(containerId, 'qrcode-vote-' + escapeAttr(cand.code));
    // petite pause pour laisser le navigateur traiter chaque téléchargement
    await new Promise(r => setTimeout(r, 350));
  }
}

(async function init() {
  // QR du site (logo au centre)
  await makeQrWithCenterImage('qr-site', SITE_URL_STR, SITE_LOGO_URL, 280);
  // QR de vote global (logo au centre)
  await makeQrWithCenterImage('qr-vote-all', VOTE_ALL_URL, SITE_LOGO_URL, 280);

  // QR de chaque candidate (photo au centre)
  for (const cand of CANDIDATES) {
    const containerId = buildCandidateCard(cand);
    await makeQrWithCenterImage(containerId, cand.url, cand.photo, 240);
  }
})();
</script>

</body>
</html>