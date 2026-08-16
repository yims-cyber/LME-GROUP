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
if (stripos($host, 'lme-group') !== false || stripos($host, 'aurora') !== false || $host === 'localhost' || $host === '127.0.0.1' || filter_var(explode(':', $host)[0], FILTER_VALIDATE_IP) || strpos($host, 'e2b.dev') !== false) {
    $subdomain = 'lme-group';
} else if (preg_match('/^(.*?)\.' . preg_quote($domain, '/') . '$/', $host, $matches)) {
    $subdomain = $matches[1];
} else {
    $subdomain = 'lme-group'; // default LME au lieu de gestion pour éviter fallback site_id=1
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

// ========== Récupération du code candidat depuis l'URL ==========
$code = isset($_GET['code']) ? trim($_GET['code']) : null;
$candidate = null;
$error = '';

if ($code) {
    // On cherche la participante par son code_participante, active, et appartenant à un concours actif du site
    $stmt = $pdo->prepare("
        SELECT p.participante_id, p.code_participante, p.nom_complet, p.age, p.ville_origine,
               p.niveau_etudes, p.taille_en_cm, p.biographie, p.cause_soutenue,
               p.situation_actuelle, p.concours_id,
               m.photo_officielle
        FROM participantes p
        LEFT JOIN medias_participantes m ON m.participante_id = p.participante_id
            AND m.est_photo_principale = 1
        JOIN concours c ON p.concours_id = c.concours_id
        WHERE p.code_participante = :code
          AND p.situation_actuelle = 'active'
          AND c.site_id = :site_id
          AND c.etat_concours IN ('actif', 'en_cours')
          AND c.arret_manuel = 0
          AND NOW() BETWEEN c.date_ouverture AND c.date_cloture
        ORDER BY m.ajoute_le DESC
        LIMIT 1
    ");
    $stmt->execute(['code' => $code, 'site_id' => $siteId]);
    $candidate = $stmt->fetch();

    if (!$candidate) {
        // Si pas de photo principale, on prend la plus récente
        if ($candidate && empty($candidate['photo_officielle'])) {
            $stmt2 = $pdo->prepare("SELECT photo_officielle FROM medias_participantes WHERE participante_id = ? ORDER BY ajoute_le DESC LIMIT 1");
            $stmt2->execute([$candidate['participante_id']]);
            $row = $stmt2->fetch();
            if ($row) {
                $candidate['photo_officielle'] = $row['photo_officielle'];
            }
        }
    }
} else {
    $error = "Aucun code candidate fourni.";
}

// Si candidate trouvée, on récupère aussi le concours_id pour les liens de vote
$concours_id = $candidate ? $candidate['concours_id'] : null;

// Récupération des étapes actives pour le concours (pour le lien voter)
$etapes = [];
if ($concours_id) {
    $stmtEtapes = $pdo->prepare("
        SELECT e.etape_id, e.numero_ordre, t.nom_etape
        FROM etapes_du_concours e
        JOIN types_etapes t ON e.type_etape_id = t.type_etape_id
        WHERE e.concours_id = ? AND e.etape_terminee = 0
          AND NOW() BETWEEN e.date_ouverture AND e.date_cloture
        ORDER BY e.numero_ordre ASC
    ");
    $stmtEtapes->execute([$concours_id]);
    $etapes = $stmtEtapes->fetchAll();
}

// Si une étape active existe, on prend la première pour le lien de vote
$etape_id = count($etapes) > 0 ? $etapes[0]['etape_id'] : null;

// Fonction pour construire l'URL de la photo - fix robuste avec fallback
function getPhotoUrl($photo_officielle) {
    if (empty($photo_officielle)) return 'https://images.unsplash.com/photo-1529626455594-4ff0802cfb7e?w=600&h=750&fit=crop';
    $p = ltrim($photo_officielle, '/');
    if (strpos($p, 'admin/') === 0) $p = substr($p, 6);
    $p = ltrim($p, '/');
    if (strpos($p, 'uploads/') !== 0) $p = 'uploads/' . $p;
    return STOCKAGE_DOMAIN . '/admin/' . $p;
}

// Fonction d'échappement
function esc($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $candidate ? esc($candidate['nom_complet']) . ' - ' . esc($siteName) : 'Candidate introuvable' ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;0,700;1,300;1,400;1,700&family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ═══════════════════════ DESIGN SYSTEM ═══════════════════════ */
        :root {
            --bg:       #0a0c14;
            --bg2:      #0d1020;
            --bg3:      #111525;
            --gold:     #c9a84c;
            --gold-lt:  #e2c06a;
            --gold-dim: rgba(201,168,76,.12);
            --gold-bdr: rgba(201,168,76,.28);
            --white:    #ffffff;
            --muted:    rgba(255,255,255,.48);
            --muted2:   rgba(255,255,255,.22);
            --green:    #22c55e;
            --radius:   14px;
            --nav-h:    70px;
        }
        *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
        html{scroll-behavior:smooth;font-size:16px;}
        body{font-family:'Montserrat',sans-serif;background:var(--bg);color:var(--white);overflow-x:hidden;-webkit-font-smoothing:antialiased;}
        a{text-decoration:none;color:inherit;}
        ::-webkit-scrollbar{width:4px;}
        ::-webkit-scrollbar-track{background:var(--bg);}
        ::-webkit-scrollbar-thumb{background:var(--gold);border-radius:4px;}
        img{display:block;}

        .btn{display:inline-flex;align-items:center;gap:8px;font-family:'Montserrat',sans-serif;font-size:.72rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;padding:12px 26px;border-radius:8px;border:none;cursor:pointer;transition:all .25s;}
        .btn-gold{background:var(--gold);color:#000;}
        .btn-gold:hover{background:var(--gold-lt);transform:translateY(-2px);box-shadow:0 8px 24px rgba(201,168,76,.35);}
        .btn-outline{background:transparent;border:1.5px solid var(--gold-bdr);color:var(--gold-lt);}
        .btn-outline:hover{background:var(--gold-dim);border-color:var(--gold);}

        /* NAV */
        .nav{
            position:fixed;top:0;left:0;right:0;z-index:900;
            height:var(--nav-h);
            background:#020409;
            backdrop-filter:blur(18px);
            border-bottom:1px solid var(--gold-bdr);
            display:flex;align-items:center;justify-content:space-between;
            padding:0 48px;
            transition:box-shadow .3s;
        }
        .nav.shadow{box-shadow:0 4px 32px rgba(0,0,0,.6);}
        .nav__logo{display:flex;align-items:center;gap:12px;}
        .nav__logo-text{font-family:'Cormorant Garamond',serif;font-size:1.15rem;font-weight:700;color:var(--gold-lt);letter-spacing:.05em;line-height:1.1;}
        .nav__logo-sub{display:block;font-family:'Montserrat',sans-serif;font-size:.52rem;font-weight:600;letter-spacing:.2em;text-transform:uppercase;color:var(--muted);}
        .nav__links{display:flex;align-items:center;gap:2px;list-style:none;}
        .nav__links a{font-size:.68rem;font-weight:600;letter-spacing:.07em;text-transform:uppercase;color:var(--muted);padding:8px 13px;border-radius:6px;transition:color .2s,background .2s;}
        .nav__links a:hover{color:var(--white);}
        .nav__links .active{color:var(--gold-lt);}
        .nav__links .cta-nav{background:var(--gold);color:#000!important;border-radius:7px;margin-left:6px;font-weight:800;}
        .nav__links .cta-nav:hover{background:var(--gold-lt);}

        /* burger (si nécessaire) */
        .burger{display:none;}
        @media(max-width:1050px){
            .nav{padding:0 20px;}
            .nav__links{display:none;}
            .burger{display:none;width:38px;height:38px;border:1px solid var(--gold-bdr);border-radius:8px;background:var(--gold-dim);align-items:center;justify-content:center;flex-direction:column;gap:4.5px;cursor:pointer;padding:9px;}
            .burger span{display:block;width:100%;height:1.5px;background:#fff;border-radius:2px;}
        }

        /* PAGE PROFIL */
        .profile-container {
            padding-top: calc(var(--nav-h) + 60px);
            padding-bottom: 60px;
            max-width: 1100px;
            margin: 0 auto;
            padding-left: 40px;
            padding-right: 40px;
        }
        .profile-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 80px;
            align-items: start;
        }
        .profile-photo {
            width: 100%;
            aspect-ratio: 3/4;
            border-radius: 20px;
            overflow: hidden;
            border: 1px solid var(--gold-bdr);
            box-shadow: 0 20px 60px rgba(0,0,0,.6);
            position: relative;
        }
        .profile-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: top;
        }
        .profile-code {
            position: absolute;
            top: 16px;
            left: 16px;
            background: var(--gold);
            color: #000;
            padding: 6px 14px;
            border-radius: 8px;
            font-size: .68rem;
            font-weight: 700;
            letter-spacing: .1em;
            text-transform: uppercase;
        }

        .profile-details {
            display: flex;
            flex-direction: column;
            gap: 28px;
        }
        .profile-details h1 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 3.2rem;
            font-weight: 700;
            line-height: 1;
            color: #fff;
        }
        .profile-details h1 em {
            display: block;
            font-style: italic;
            font-weight: 300;
            font-size: 1.8rem;
            color: var(--gold-lt);
            margin-top: 8px;
        }
        .profile-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }
        .meta-badge {
            background: var(--gold-dim);
            border: 1px solid var(--gold-bdr);
            padding: 8px 16px;
            border-radius: 30px;
            font-size: .7rem;
            font-weight: 600;
            letter-spacing: .06em;
            color: var(--gold-lt);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .bio-block {
            margin-top: 10px;
        }
        .bio-block h3 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--gold-lt);
            margin-bottom: 12px;
        }
        .bio-block p {
            font-size: .9rem;
            line-height: 1.8;
            color: var(--muted);
        }
        .theme-cause {
            margin-top: 10px;
            padding: 18px 20px;
            background: rgba(255,255,255,.02);
            border-left: 3px solid var(--gold);
            border-radius: 10px;
        }
        .theme-cause h4 {
            font-size: .65rem;
            font-weight: 700;
            letter-spacing: .15em;
            text-transform: uppercase;
            color: var(--gold);
            margin-bottom: 8px;
        }
        .theme-cause p {
            font-size: .85rem;
            color: var(--muted);
            line-height: 1.7;
        }
        .video-wrapper {
            margin-top: 20px;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid var(--gold-bdr);
        }
        .video-wrapper iframe,
        .video-wrapper video {
            width: 100%;
            height: auto;
            aspect-ratio: 16/9;
            display: block;
        }
        .btn-row {
            display: flex;
            gap: 14px;
            margin-top: 10px;
        }

        /* Footer simplifié */
        .mini-footer {
            text-align: center;
            padding: 40px;
            color: var(--muted2);
            font-size: .72rem;
            border-top: 1px solid var(--gold-bdr);
            margin-top: 60px;
        }

        /* Responsive */
        @media(max-width: 768px) {
            .profile-grid {
                grid-template-columns: 1fr;
                gap: 40px;
            }
            .profile-details h1 {
                font-size: 2.4rem;
            }
            .profile-container {
                padding-left: 20px;
                padding-right: 20px;
            }
        }
    </style>
</head>
<body>

<!-- ══ NAV ══ -->
<nav class="nav" id="nav">
    <a href="index.php" class="nav__logo">
        <?php if ($siteLogoConcours && !empty($siteLogoExtension)): ?>
            <img style="height:50px;object-fit:contain;" src="<?= STOCKAGE_DOMAIN . '/admin/uploads/sites_logo/' . $siteLien . '.' . $siteLogoExtension . '?v=' . time() ?>" alt="Logo <?= esc($siteName) ?>">
        <?php else: ?>
            <img width="90px" src="millenium.webp" alt="logo"/>
        <?php endif; ?>
        <span class="nav__logo-text"><?= esc($siteName) ?>
            <span class="nav__logo-sub">Kinshasa · 4ᵉ Édition 2026</span>
        </span>
    </a>
    <ul class="nav__links">
        <li><a href="index.php#accueil">Accueil</a></li>
        <li><a href="index.php#concept">À propos</a></li>
        <li><a href="index.php#phases">Compétition</a></li>
        <li><a href="index.php#candidates" class="active">Candidates</a></li>
        <li><a href="index.php#partenariat">Partenaires</a></li>
        <li><a href="index.php#casting">Casting</a></li>
        <li><a href="index.php#contact">Contact</a></li>
        <li><a href="candidatures.php" class="cta-nav">S'inscrire</a></li>
    </ul>
    <div class="burger" id="burger"><span></span><span></span><span></span></div>
</nav>

<!-- ══ CONTENU PROFIL ══ -->
<div class="profile-container">
    <?php if ($candidate): ?>
        <div class="profile-grid">
            <!-- Photo -->
            <div class="profile-photo">
                <img src="<?= getPhotoUrl($candidate['photo_officielle']) ?>" alt="<?= esc($candidate['nom_complet']) ?>">
                <div class="profile-code">N° <?= esc($candidate['code_participante']) ?></div>
            </div>

            <!-- Infos -->
            <div class="profile-details">
                <h1>
                    <?= esc($candidate['nom_complet']) ?>
                    <em>Candidate Officielle</em>
                </h1>

                <div class="profile-meta">
                    <?php if (!empty($candidate['age'])): ?>
                        <div class="meta-badge">🎂 <?= esc($candidate['age']) ?> ans</div>
                    <?php endif; ?>
                    <?php if (!empty($candidate['ville_origine'])): ?>
                        <div class="meta-badge">📍 <?= esc($candidate['ville_origine']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($candidate['niveau_etudes'])): ?>
                        <div class="meta-badge">🎓 <?= esc($candidate['niveau_etudes']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($candidate['taille_en_cm'])): ?>
                        <div class="meta-badge">📏 <?= esc($candidate['taille_en_cm']) ?> cm</div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($candidate['biographie'])): ?>
                <div class="bio-block">
                    <h3>Biographie</h3>
                    <p><?= nl2br(esc($candidate['biographie'])) ?></p>
                </div>
                <?php endif; ?>

                <?php if (!empty($candidate['cause_soutenue'])): ?>
                <div class="theme-cause">
                    <h4>Cause défendue</h4>
                    <p><?= esc($candidate['cause_soutenue']) ?></p>
                </div>
                <?php endif; ?>

                <!-- Video placeholder if any; table has no video column, but we keep for compatibility -->
                <?php if (isset($candidate['video']) && !empty($candidate['video'])): ?>
                <div class="video-wrapper">
                    <?php
                    $videoUrl = esc($candidate['video']);
                    if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]+)/', $videoUrl, $matches)) {
                        echo '<iframe src="https://www.youtube.com/embed/' . $matches[1] . '" frameborder="0" allowfullscreen></iframe>';
                    } else {
                        echo '<video controls src="' . $videoUrl . '"></video>';
                    }
                    ?>
                </div>
                <?php endif; ?>

                <div class="btn-row">
                    <a href="voter.php?candidat=<?= urlencode($candidate['participante_id']) ?>&concours_id=<?= urlencode($concours_id) ?><?= $etape_id ? '&etape_id=' . urlencode($etape_id) : '' ?>" class="btn btn-gold">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 22 12 18.56 5.82 22 7 14.14l-5-4.87 6.91-1.01L12 2z"/></svg>
                        Voter pour elle
                    </a>
                    <a href="index.php#candidates" class="btn btn-outline">← Retour aux candidates</a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div style="text-align:center; padding: 100px 20px;">
            <h2 style="font-family:'Cormorant Garamond',serif; font-size:2.5rem; color:var(--gold-lt); margin-bottom:20px;">Candidate introuvable</h2>
            <p style="color:var(--muted); margin-bottom:30px;">Aucune candidate active ne correspond à ce code.</p>
            <a href="index.php#candidates" class="btn btn-gold">Voir toutes les candidates</a>
        </div>
    <?php endif; ?>
</div>

<!-- ══ MINI FOOTER ══ -->
<div class="mini-footer">
    © 2026 <?= esc($siteName) ?> · 4ᵉ Édition
</div>

<script>
    // Nav shadow on scroll
    const nav = document.getElementById('nav');
    window.addEventListener('scroll', () => nav.classList.toggle('shadow', scrollY > 50), {passive:true});
</script>
</body>
</html>