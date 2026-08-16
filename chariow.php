<?php
// chariow.php — Page de vote avec paiement par carte (Chariow)
// Trois packs : 50 votes 20$, 100 votes 40$, 250 votes 100$

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

define('DB_HOST', 'localhost:3306');
define('DB_NAME', 'mayi1275_zaloria_multisysteme');
define('DB_USER', 'mayi1275_zaloriatech');
define('DB_PASS', '07/09/1996/O2switch');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die("Erreur connexion : " . $e->getMessage());
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

// ── Récupération des paramètres GET ──
$candidate_id = isset($_GET['candidat']) ? intval($_GET['candidat']) : 0;
$concours_id_param = isset($_GET['concours_id']) ? intval($_GET['concours_id']) : 0;
$etape_id_param    = isset($_GET['etape_id'])    ? intval($_GET['etape_id'])    : 0;

$candidate = null;
$error = '';

if ($candidate_id > 0) {
    $stmt = $pdo->prepare("
        SELECT p.participante_id, p.code_participante, p.nom_complet, p.ville_origine,
               p.niveau_etudes, p.situation_actuelle, p.concours_id,
               m.photo_officielle
        FROM participantes p
        LEFT JOIN medias_participantes m ON m.participante_id = p.participante_id
            AND m.est_photo_principale = 1
        WHERE p.participante_id = :id AND p.situation_actuelle = 'active'
        ORDER BY m.ajoute_le DESC
        LIMIT 1
    ");
    $stmt->execute(['id' => $candidate_id]);
    $candidate = $stmt->fetch();
    if (!$candidate) {
        $error = "Candidate introuvable ou non active.";
    } else {
        if (empty($candidate['photo_officielle'])) {
            $stmt2 = $pdo->prepare("
                SELECT photo_officielle
                FROM medias_participantes
                WHERE participante_id = ?
                ORDER BY ajoute_le DESC
                LIMIT 1
            ");
            $stmt2->execute([$candidate_id]);
            $row = $stmt2->fetch();
            if ($row) {
                $candidate['photo_officielle'] = $row['photo_officielle'];
            }
        }
    }
} else {
    $error = "Aucune candidate sélectionnée.";
}

// ── Vérification du concours et de l'étape ──
$concours_id = null;
$etape_id = null;
$votes_candidate = 0;

if ($candidate) {
    if ($concours_id_param > 0) {
        if ($concours_id_param != $candidate['concours_id']) {
            $error = "Le concours spécifié ne correspond pas à celui de la candidate.";
        } else {
            $concours_id = $concours_id_param;
        }
    } else {
        $concours_id = $candidate['concours_id'];
    }

    if ($concours_id && $etape_id_param > 0) {
        $stmtEtape = $pdo->prepare("
            SELECT etape_id, date_ouverture, date_cloture, etape_terminee
            FROM etapes_du_concours
            WHERE etape_id = :eid AND concours_id = :cid
        ");
        $stmtEtape->execute(['eid' => $etape_id_param, 'cid' => $concours_id]);
        $etape = $stmtEtape->fetch();
        if (!$etape) {
            $error = "L'étape spécifiée n'existe pas pour ce concours.";
        } elseif ($etape['etape_terminee'] == 1) {
            $error = "Cette étape est terminée, vous ne pouvez plus voter.";
        } else {
            $now = time();
            $debut = strtotime($etape['date_ouverture']);
            $fin   = strtotime($etape['date_cloture']);
            if ($now < $debut) {
                $error = "Cette étape n'est pas encore ouverte (début le " . date('d/m/Y', $debut) . ").";
            } elseif ($now > $fin) {
                $error = "Cette étape est déjà terminée (clôture le " . date('d/m/Y', $fin) . ").";
            } else {
                $etape_id = $etape_id_param;
            }
        }
    } else {
        $etape_id = null;
    }

    // Récupérer les votes actuels de la candidate
    if (!$error) {
        $sqlVotes = "
            SELECT COALESCE(SUM(votes_accordes), 0) as total
            FROM transactions_votes
            WHERE participante_id = :pid
              AND concours_id = :cid
              AND etat_paiement = 'confirme'
        ";
        $paramsVotes = ['pid' => $candidate_id, 'cid' => $concours_id];
        if ($etape_id !== null) {
            $sqlVotes .= " AND etape_id = :eid";
            $paramsVotes['eid'] = $etape_id;
        }
        $stmtVotes = $pdo->prepare($sqlVotes);
        $stmtVotes->execute($paramsVotes);
        $votes_candidate = $stmtVotes->fetchColumn() ?: 0;
    }
}

// ── Fonction getClassement (identique à voter.php) ──
function getClassement($pdo, $concours_id, $etape_id = null, $period = 'global') {
    $dateCondition = '';
    switch ($period) {
        case 'jour':
            $dateCondition = "AND DATE(t.confirme_le) = CURDATE()";
            break;
        case 'semaine':
            $dateCondition = "AND YEARWEEK(t.confirme_le, 1) = YEARWEEK(CURDATE(), 1)";
            break;
        case 'mois':
            $dateCondition = "AND MONTH(t.confirme_le) = MONTH(CURDATE()) AND YEAR(t.confirme_le) = YEAR(CURDATE())";
            break;
        default:
            $dateCondition = '';
    }

    $etapeCondition = '';
    $joinEtape = '';
    $params = ['cid' => $concours_id, 'cid2' => $concours_id];
    if ($etape_id !== null) {
        $etapeCondition = "AND t.etape_id = :eid";
        $joinEtape = "INNER JOIN parcours_participantes pe ON p.participante_id = pe.participante_id AND pe.etape_id = :eid";
        $params['eid'] = $etape_id;
    }

    $sql = "
        SELECT p.participante_id, p.nom_complet, p.code_participante, p.ville_origine,
               COALESCE(SUM(t.votes_accordes), 0) as total_votes,
               m.photo_officielle
        FROM participantes p
        $joinEtape
        LEFT JOIN transactions_votes t ON t.participante_id = p.participante_id
            AND t.etat_paiement = 'confirme'
            AND t.concours_id = :cid
            $dateCondition
            $etapeCondition
        LEFT JOIN medias_participantes m ON m.participante_id = p.participante_id
            AND m.est_photo_principale = 1
        WHERE p.situation_actuelle = 'active' AND p.concours_id = :cid2
        GROUP BY p.participante_id
        ORDER BY total_votes DESC, p.nom_complet ASC
        LIMIT 20
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        if (empty($r['photo_officielle'])) {
            $stmt2 = $pdo->prepare("
                SELECT photo_officielle
                FROM medias_participantes
                WHERE participante_id = ?
                ORDER BY ajoute_le DESC
                LIMIT 1
            ");
            $stmt2->execute([$r['participante_id']]);
            $row2 = $stmt2->fetch();
            if ($row2) {
                $r['photo_officielle'] = $row2['photo_officielle'];
            }
        }
    }
    unset($r);
    return $rows;
}

$classements = [];
if ($candidate && $concours_id) {
    $classements = [
        'jour'    => getClassement($pdo, $concours_id, $etape_id, 'jour'),
        'semaine' => getClassement($pdo, $concours_id, $etape_id, 'semaine'),
        'mois'    => getClassement($pdo, $concours_id, $etape_id, 'mois'),
        'global'  => getClassement($pdo, $concours_id, $etape_id, 'global'),
    ];
}

// ── Fonction pour l'URL de la photo ──
function getPhotoUrl($photo_officielle) {
    if (empty($photo_officielle)) return 'placeholder.jpg';
    $path = ltrim($photo_officielle, '/');
    if (strpos($path, 'admin/') !== 0) {
        $path = 'admin/' . $path;
    }
    return STOCKAGE_DOMAIN . '/' . $path;
}

function renderClassementRow($c, $rank) {
    $photo = getPhotoUrl($c['photo_officielle']);
    $name = htmlspecialchars($c['nom_complet']);
    $code = htmlspecialchars($c['code_participante']);
    $ville = htmlspecialchars($c['ville_origine'] ?? '');
    $votes = $c['total_votes'];
    $medal = $rank <= 3 ? ['🥇','🥈','🥉'][$rank-1] : $rank;
    return "<div class='class-row'>
                <span class='class-rank'>$medal</span>
                <img src='$photo' alt='$name' class='class-thumb'>
                <div class='class-info'>
                    <strong>$name</strong>
                    <small>N°$code · $ville</small>
                </div>
                <span class='class-votes'>$votes votes</span>
            </div>";
}

// ── MÉTA DONNÉES POUR LES RÉSEAUX SOCIAUX ──
$metaUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
$metaTitle = "Voter pour " . ($candidate ? $candidate['nom_complet'] : 'Candidate') . " - " . htmlspecialchars($siteName);

if ($candidate) {
    $candidateName = $candidate['nom_complet'];
    $code = $candidate['code_participante'];
    $ville = $candidate['ville_origine'] ?? 'Kinshasa';
    $metaDescription = "🌟 PRÉ-CASTING MISS KINSHASA 2026 🌟\n";
    $metaDescription .= "Votre candidate a besoin de votre soutien !\n";
    $metaDescription .= "👑 " . $candidateName . " 🔢 Candidate N°" . $code . " 📍 Commune : " . $ville . " – Kinshasa\n";
    $metaDescription .= "🗳️ Votez dès maintenant et aidez-la à franchir cette étape importante de la compétition.\n";
    $metaDescription .= "👉 Cliquez ici pour voter : " . $metaUrl . "\n";
    $metaDescription .= "Chaque vote compte ! Ensemble, propulsons la candidate " . $code . " " . $candidateName . " vers la prochaine étape du concours Miss Kinshasa 2026.\n";
    $metaDescription .= "#MissKinshasa2026 #Vote" . $code . " #" . str_replace(' ', '', $candidateName) . " #" . str_replace(' ', '', $ville) . " #FièreDêtreKinoise #MissKinshasa #PréCasting";
    $metaImage = getPhotoUrl($candidate['photo_officielle']);
} else {
    $metaDescription = "🌟 PRÉ-CASTING MISS KINSHASA 2026 🌟\nVotez pour votre candidate préférée !";
    $metaImage = $siteLogoUrl ?: STOCKAGE_DOMAIN . '/millenium.webp';
}

if (empty($metaImage) || $metaImage === 'placeholder.jpg') {
    $concoursLogo = '';
    if ($concours_id > 0) {
        $stmtLogo = $pdo->prepare("SELECT url_concours, logo_concours, logo_extension FROM concours WHERE concours_id = ?");
        $stmtLogo->execute([$concours_id]);
        $rowLogo = $stmtLogo->fetch();
        if ($rowLogo && $rowLogo['logo_concours'] && !empty($rowLogo['url_concours']) && !empty($rowLogo['logo_extension'])) {
            $concoursLogo = STOCKAGE_DOMAIN . '/admin/uploads/logo_concours/' . $rowLogo['url_concours'] . '.' . $rowLogo['logo_extension'] . '?v=' . time();
        }
    }
    $metaImage = $concoursLogo ?: ($siteLogoUrl ?: STOCKAGE_DOMAIN . '/millenium.webp');
}

$metaDescriptionClean = preg_replace('/\n+/', ' ', $metaDescription);
$metaDescriptionClean = trim($metaDescriptionClean);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($metaTitle) ?></title>

    <meta name="description" content="<?= htmlspecialchars($metaDescriptionClean) ?>">
    <meta property="og:title" content="<?= htmlspecialchars($metaTitle) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($metaDescriptionClean) ?>">
    <meta property="og:image" content="<?= htmlspecialchars($metaImage) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($metaUrl) ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= htmlspecialchars($siteName) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($metaTitle) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($metaDescriptionClean) ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($metaImage) ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">

    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;0,700;1,300;1,400;1,700&family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:#0a0c14;--bg2:#0d1020;--gold:#c9a84c;--gold-lt:#e2c06a;
            --gold-dim:rgba(201,168,76,.12);--gold-bdr:rgba(201,168,76,.28);
            --white:#ffffff;--muted:rgba(255,255,255,.48);--muted2:rgba(255,255,255,.22);
            --green:#22c55e;--radius:14px;--nav-h:70px;
        }
        *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Montserrat',sans-serif;background:var(--bg);color:var(--white);overflow-x:hidden}
        a{text-decoration:none;color:inherit}
        .btn{display:inline-flex;align-items:center;gap:8px;font-weight:700;font-size:.72rem;text-transform:uppercase;padding:12px 26px;border-radius:8px;border:none;cursor:pointer;transition:all .25s}
        .btn-gold{background:var(--gold);color:#000}
        .btn-gold:hover{background:var(--gold-lt);transform:translateY(-2px)}
        .btn-outline{background:transparent;border:1.5px solid var(--gold-bdr);color:var(--gold-lt)}
        .btn-outline:hover{background:var(--gold-dim);border-color:var(--gold)}
        .btn:disabled{opacity:0.5;cursor:not-allowed;transform:none!important;box-shadow:none!important}

        .nav{position:fixed;top:0;left:0;right:0;z-index:900;height:var(--nav-h);background:#020409;backdrop-filter:blur(18px);border-bottom:1px solid var(--gold-bdr);display:flex;align-items:center;justify-content:space-between;padding:0 48px;transition:box-shadow .3s}
        .nav.shadow{box-shadow:0 4px 32px rgba(0,0,0,.6)}
        .nav__logo{display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit}
        .nav__logo-img{height:50px;object-fit:contain}
        .nav__logo-text{font-family:'Cormorant Garamond',serif;font-size:1.15rem;font-weight:700;color:var(--gold-lt);letter-spacing:.05em;line-height:1.1}
        .nav__logo-sub{display:block;font-family:'Montserrat',sans-serif;font-size:.52rem;font-weight:600;letter-spacing:.2em;text-transform:uppercase;color:var(--muted)}
        .nav__links{display:flex;align-items:center;gap:2px;list-style:none}
        .nav__links a{font-size:.68rem;font-weight:600;letter-spacing:.07em;text-transform:uppercase;color:var(--muted);padding:8px 13px;border-radius:6px;transition:.2s}
        .nav__links a:hover,.nav__links .active{color:var(--gold-lt)}
        .nav__links .cta-nav{background:var(--gold);color:#000!important;margin-left:6px;font-weight:800}
        @media(max-width:1050px){.nav{padding:0 20px}.nav__links{display:none}}
          
        .container{max-width:1100px;margin:0 auto;padding:calc(var(--nav-h) + 40px) 40px 60px}
        h1{font-family:'Cormorant Garamond',serif;font-size:2.8rem;font-weight:700;text-align:center;margin-bottom:10px}
        h1 em{font-style:italic;color:var(--gold-lt)}
        .card{background:var(--bg2);border:1px solid var(--gold-bdr);border-radius:20px;padding:30px;margin-bottom:30px}
        .candidate-header{display:flex;gap:30px;align-items:center;margin-bottom:30px}
        .candidate-header img{width:120px;height:120px;border-radius:20px;object-fit:cover;border:2px solid var(--gold-bdr)}
        .candidate-header h2{font-family:'Cormorant Garamond',serif;font-size:2rem}
        .candidate-header .code{display:inline-block;background:var(--gold);color:#000;padding:4px 14px;border-radius:6px;font-size:.7rem;font-weight:700;letter-spacing:.1em;margin-bottom:8px}
        .votes-count{background:var(--gold-dim);border:1px solid var(--gold-bdr);border-radius:14px;padding:16px;text-align:center;margin-bottom:20px}
        .votes-count .num{font-size:2.5rem;font-weight:700;color:var(--gold-lt)}

        /* Pack selector */
        .pack-selector {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 24px;
            justify-content: center;
        }
        .pack-option {
            flex: 1 1 160px;
            background: rgba(255,255,255,.03);
            border: 2px solid rgba(255,255,255,.08);
            border-radius: 14px;
            padding: 18px 12px;
            text-align: center;
            cursor: pointer;
            transition: all .25s;
        }
        .pack-option:hover {
            border-color: var(--gold-bdr);
            background: rgba(201,168,76,.04);
        }
        .pack-option.selected {
            border-color: var(--gold);
            background: rgba(201,168,76,.08);
            box-shadow: 0 0 20px rgba(201,168,76,.15);
        }
        .pack-option .votes {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--gold-lt);
        }
        .pack-option .price {
            font-size: 1.1rem;
            color: var(--muted);
            margin-top: 4px;
        }
        .pack-option .price strong {
            color: var(--white);
        }

        .form-group{margin-bottom:16px}
        .form-group label{display:block;font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:var(--muted2);margin-bottom:6px}
        .form-group input,.form-group select{width:100%;padding:12px 15px;border-radius:9px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.09);color:#fff;font-family:'Montserrat',sans-serif;font-size:.82rem;outline:none;transition:.2s}
        .form-group input:focus,.form-group select:focus{border-color:var(--gold);background:rgba(201,168,76,.04)}
        .form-group select{appearance:none;cursor:pointer;background:#fff;color:#000;}

        .summary{background:rgba(201,168,76,.05);border:1px solid var(--gold-bdr);border-radius:12px;padding:16px;margin-bottom:20px}
        .summary-row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:.83rem;color:var(--muted)}
        .summary-row:last-child{border:none}
        .total{color:var(--gold-lt);font-weight:700}

        .error-msg{color:#ff6b6b;font-size:.8rem;margin-top:4px;display:none}
        .error-msg.show{display:block}

        .loading-state{display:none;text-align:center;padding:32px}
        .loading-state.show{display:block}
        .spinner{width:40px;height:40px;border:3px solid rgba(201,168,76,.15);border-top-color:var(--gold);border-radius:50%;animation:spin .75s linear infinite;margin:0 auto 14px}
        @keyframes spin{to{transform:rotate(360deg)}}
        .loading-msg{color:var(--muted);font-size:.9rem}

        .receipt-container{display:none;background:var(--bg2);border:1px solid var(--gold-bdr);border-radius:20px;padding:30px;max-width:520px;margin:40px auto;text-align:center}
        .receipt-container.show{display:block}
        .receipt-details{text-align:left;background:rgba(255,255,255,.03);border-radius:12px;padding:16px;margin:20px 0}
        .receipt-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:.85rem}
        .receipt-row:last-child{border:none}
        .receipt-actions{display:flex;flex-direction:column;gap:12px;margin-top:20px}
        .receipt-actions .btn{width:100%;justify-content:center}
        .print-btn{margin-top:0}

        .classement-tabs{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap}
        .tab-btn{padding:8px 20px;border-radius:20px;font-size:.7rem;font-weight:600;text-transform:uppercase;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);color:var(--muted);cursor:pointer;transition:.2s}
        .tab-btn.active{background:var(--gold);color:#000;border-color:var(--gold)}
        .class-row{display:flex;align-items:center;gap:14px;padding:12px 0;border-bottom:1px solid rgba(255,255,255,.05)}
        .class-rank{font-weight:700;width:30px;text-align:center}
        .class-thumb{width:40px;height:40px;border-radius:8px;object-fit:cover}
        .class-info{flex:1}
        .class-info strong{display:block;font-size:.85rem}
        .class-info small{font-size:.7rem;color:var(--muted)}
        .class-votes{font-weight:700;color:var(--gold-lt);white-space:nowrap}

        .share-section{margin-top:20px;padding-top:20px;border-top:1px solid var(--gold-bdr)}
        .share-lbl{font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.15em;color:var(--muted2);margin-bottom:12px}
        .share-btns{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
        .share-btn{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:20px;background:rgba(255,255,255,.05);border:1px solid var(--gold-bdr);color:var(--muted);font-size:.7rem;cursor:pointer;transition:.2s;text-decoration:none}
        .share-btn:hover{background:var(--gold-dim);border-color:var(--gold);color:var(--white)}

        @media(max-width:768px){
            .container{padding-left:10px;padding-right:10px}
            .candidate-header{flex-direction:column;text-align:center}
            .pack-option{flex:1 1 100%}
        }
        @media print {
            body * { visibility: hidden; }
            #receiptBlock, #receiptBlock * { visibility: visible; }
            #receiptBlock {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                padding: 20px;
                background: white !important;
                color: black !important;
            }
            #receiptBlock .btn { display: none !important; }
            #receiptBlock .receipt-details { border-color: #ccc; }
            #receiptBlock strong { color: black; }
        }
    </style>
</head>
<body>
<nav class="nav" id="nav">
    <a href="index.php" class="nav__logo">
        <?php if ($siteLogoUrl): ?>
            <img class="nav__logo-img" src="<?= $siteLogoUrl ?>" alt="Logo <?= htmlspecialchars($siteName) ?>">
        <?php else: ?>
            <img class="nav__logo-img" src="millenium.webp" alt="Logo par défaut">
        <?php endif; ?>
        <span class="nav__logo-text"><?= htmlspecialchars($siteName) ?> <span class="nav__logo-sub">Kinshasa · 4ᵉ Édition 2026</span></span>
    </a>
    <ul class="nav__links">
        <li><a href="index.php#accueil">Accueil</a></li>
        <li><a href="index.php#concept">À propos</a></li>
        <li><a href="index.php#phases">Compétition</a></li>
        <li><a href="index.php#candidates">Candidates</a></li>
        <li><a href="index.php#partenariat">Partenaires</a></li>
        <li><a href="index.php#casting">Casting</a></li>
        <li><a href="index.php#contact">Contact</a></li>
        <li><a href="candidatures.php" class="cta-nav">S'inscrire</a></li>
    </ul>
</nav>

<div class="container">
    <?php if ($error): ?>
        <div class="alert alert-error" style="padding:14px 18px;border-radius:10px;margin-bottom:20px;font-weight:600;background:rgba(255,0,0,.1);border:1px solid rgba(255,0,0,.3);color:#ff6b6b;"><?= $error ?></div>
        <?php if (!$candidate): ?>
            <div style="text-align:center"><a href="index.php#candidates" class="btn btn-outline">← Voir les candidates</a></div>
        <?php endif; ?>
    <?php else: ?>
        <h1>Voter pour <em><?= htmlspecialchars($candidate['nom_complet']) ?></em></h1>

        <div class="card">
            <div class="candidate-header">
                <img src="<?= getPhotoUrl($candidate['photo_officielle']) ?>" alt="">
                <div>
                    <span class="code">N° <?= htmlspecialchars($candidate['code_participante']) ?></span>
                    <h2><?= htmlspecialchars($candidate['nom_complet']) ?></h2>
                    <p style="color:var(--muted)"><?= htmlspecialchars($candidate['ville_origine'] ?? 'Kinshasa') ?></p>
                </div>
            </div>
            <div class="votes-count">
                <div class="num" id="votes-actuels"><?= $votes_candidate ?></div>
                <div style="color:var(--muted)">votes déjà reçus</div>
            </div>

            <input type="hidden" id="concours_id" value="<?= $concours_id ?>">
            <input type="hidden" id="candidate_id" value="<?= $candidate_id ?>">
            <input type="hidden" id="etape_id" value="<?= $etape_id ?>">

            <!-- Sélection du pack -->
            <div class="pack-selector" id="packSelector">
                <div class="pack-option selected" data-pack='{"id":50,"votes":50,"usd":20}'>
                    <div class="votes">50 votes</div>
                    <div class="price">20 <strong>USD</strong></div>
                </div>
                <div class="pack-option" data-pack='{"id":100,"votes":100,"usd":40}'>
                    <div class="votes">100 votes</div>
                    <div class="price">40 <strong>USD</strong></div>
                </div>
                <div class="pack-option" data-pack='{"id":250,"votes":250,"usd":100}'>
                    <div class="votes">250 votes</div>
                    <div class="price">100 <strong>USD</strong></div>
                </div>
            </div>

            <!-- Formulaire : email et téléphone (optionnel) -->
            <div class="form-group">
                <label>Adresse e-mail</label>
                <input type="email" id="emailInput" placeholder="votant@exemple.com" required>
                <p class="error-msg" id="err-email">Veuillez saisir une adresse email valide.</p>
            </div>
            <div class="form-group">
                <label>Téléphone (optionnel)</label>
                <input type="tel" id="phoneInput" placeholder="+243 812345678">
            </div>

            <div class="summary" id="summaryBlock">
                <div class="summary-row"><span>Candidate</span><span><?= htmlspecialchars($candidate['nom_complet']) ?></span></div>
                <div class="summary-row"><span>Votes</span><span id="summaryVotes">50</span></div>
                <div class="summary-row"><span>Montant</span><span class="total" id="summaryAmount">20,00 USD</span></div>
            </div>

            <div class="error-msg" id="err-global" style="text-align:center;margin-bottom:12px;"></div>

            <div class="actions" style="text-align:center; margin-top:24px; display:flex; flex-wrap:wrap; gap:10px; justify-content:center;">
                <button type="button" id="payBtn" class="btn btn-gold" style="flex:1; min-width:200px; justify-content:center;">
                    💳 Payer par carte
                </button>
                <button type="button" id="copyLinkBtn" class="btn btn-outline" style="flex:1; min-width:200px; justify-content:center;">
                    📋 Copier le lien de partage
                </button>
            </div>
            <div id="copyFeedback" style="text-align:center; margin-top:8px; font-size:0.8rem; color:var(--muted); display:none;">Lien copié ! Partagez-le sur vos réseaux.</div>
        </div>

        <div id="loadingBlock" class="loading-state">
            <div class="spinner"></div>
            <p class="loading-msg" id="loadingMsg">Ouverture du paiement sécurisé…</p>
        </div>

        <div id="receiptBlock" class="receipt-container">
            <h2 style="font-family:'Cormorant Garamond',serif;">Reçu de Vote</h2>
            <p style="color:var(--muted)"><?= htmlspecialchars($siteName) ?></p>
            <div class="receipt-details">
                <div class="receipt-row"><span>Référence</span><span><strong id="rc-ref">—</strong></span></div>
                <div class="receipt-row"><span>Candidate</span><span id="rc-cand">—</span></div>
                <div class="receipt-row"><span>Votes achetés</span><span id="rc-votes">—</span></div>
                <div class="receipt-row"><span>Montant</span><span id="rc-amount">—</span></div>
                <div class="receipt-row"><span>Email</span><span id="rc-email">—</span></div>
                <div class="receipt-row"><span>Date</span><span id="rc-date">—</span></div>
                <div class="receipt-row"><span>Statut</span><span id="rc-statut">—</span></div>
            </div>
            <div class="receipt-actions">
                <button class="btn btn-gold print-btn" onclick="window.print()">📥 Télécharger le reçu (PDF)</button>
                <a href="chariow.php?candidat=<?= $candidate_id ?>&concours_id=<?= $concours_id ?>&etape_id=<?= $etape_id ?>" class="btn btn-outline">Voter à nouveau</a>
            </div>
        </div>

        <!-- ── CLASSEMENTS ── -->
        <div class="card">
            <h3 style="font-family:'Cormorant Garamond',serif;font-size:1.8rem;margin-bottom:20px;">Classements</h3>
            <div class="classement-tabs" id="classTabs">
                <button class="tab-btn active" data-periode="jour">Aujourd'hui</button>
                <button class="tab-btn" data-periode="semaine">Cette semaine</button>
                <button class="tab-btn" data-periode="mois">Ce mois</button>
                <button class="tab-btn" data-periode="global">Global</button>
            </div>
            <div id="classContent">
                <?php foreach ($classements as $periode => $liste): ?>
                    <div class="class-period" data-periode="<?= $periode ?>" style="display:<?= $periode === 'jour' ? 'block' : 'none' ?>">
                        <?php if (count($liste) === 0): ?>
                            <p style="color:var(--muted)">Aucune participante inscrite à cette étape.</p>
                        <?php else: ?>
                            <?php $rank = 1; foreach ($liste as $c): ?>
                                <?= renderClassementRow($c, $rank++) ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
// ========== Configuration ==========
const CANDIDATE_ID = <?= $candidate_id ?>;
const CONCOURS_ID = <?= $concours_id ?>;
const ETAPE_ID = <?= json_encode($etape_id) ?>; // peut être null
const CANDIDATE_NAME = "<?= htmlspecialchars($candidate['nom_complet'] ?? '') ?>";
const CANDIDATE_PHOTO = "<?= getPhotoUrl($candidate['photo_officielle'] ?? '') ?>";

// Packs définis dans le sélecteur
const PACKS = {
    50: { id: 50, votes: 50, usd: 20 },
    100: { id: 100, votes: 100, usd: 40 },
    250: { id: 250, votes: 250, usd: 100 }
};
let selectedPack = PACKS[50]; // par défaut

// API endpoints
const API_ENDPOINT = 'vote_chariow_api.php';

// ========== Éléments DOM ==========
const payBtn = document.getElementById('payBtn');
const copyLinkBtn = document.getElementById('copyLinkBtn');
const copyFeedback = document.getElementById('copyFeedback');
const loadingBlock = document.getElementById('loadingBlock');
const loadingMsg = document.getElementById('loadingMsg');
const receiptBlock = document.getElementById('receiptBlock');

const emailInput = document.getElementById('emailInput');
const phoneInput = document.getElementById('phoneInput');

const summaryVotes = document.getElementById('summaryVotes');
const summaryAmount = document.getElementById('summaryAmount');

const packOptions = document.querySelectorAll('.pack-option');

// ========== Sélection du pack ==========
packOptions.forEach(opt => {
    opt.addEventListener('click', function() {
        packOptions.forEach(o => o.classList.remove('selected'));
        this.classList.add('selected');
        const packData = JSON.parse(this.dataset.pack);
        selectedPack = PACKS[packData.id];
        updateSummary();
    });
});

function updateSummary() {
    summaryVotes.textContent = selectedPack.votes;
    summaryAmount.textContent = selectedPack.usd.toFixed(2) + ' USD';
}

// ========== Paiement Chariow ==========
let currentRef = null;
let pollTimer = null;
let pollStart = null;

payBtn.addEventListener('click', async () => {
    // Réinitialiser les erreurs
    document.querySelectorAll('.error-msg').forEach(el => el.classList.remove('show'));

    // Valider email
    const email = emailInput.value.trim();
    if (!email || !email.includes('@') || !email.includes('.')) {
        document.getElementById('err-email').classList.add('show');
        return;
    }

    const phone = phoneInput.value.trim();

    hideAllBlocks();
    loadingBlock.style.display = 'block';
    loadingMsg.textContent = 'Ouverture du paiement sécurisé…';

    const formData = new FormData();
    formData.append('action', 'initiate_chariow');
    formData.append('pack_id', selectedPack.id);
    formData.append('votes', selectedPack.votes);
    formData.append('amount_usd', selectedPack.usd);
    formData.append('candidate_id', CANDIDATE_ID);
    formData.append('concours_id', CONCOURS_ID);
    formData.append('etape_id', ETAPE_ID);
    formData.append('email', email);
    formData.append('phone', phone);

    try {
        const resp = await fetch(API_ENDPOINT, { method: 'POST', body: formData });
        const data = await resp.json();

        if (!data.success) {
            showFormBlock();
            document.getElementById('err-global').textContent = data.message || 'Erreur inconnue.';
            document.getElementById('err-global').classList.add('show');
            return;
        }

        const reference = data.reference;
        const checkoutUrl = data.checkout_url;

        loadingMsg.textContent = 'Redirection vers la page de paiement…';
        sessionStorage.setItem('chariow_pending_ref', reference);

        // Ouvrir la popup
        const popup = window.open(checkoutUrl, 'chariow_payment', 'width=480,height=700,scrollbars=yes,resizable=yes');
        if (!popup || popup.closed) {
            showFormBlock();
            document.getElementById('err-global').textContent = 'Popup bloquée. Veuillez autoriser les popups pour ce site.';
            document.getElementById('err-global').classList.add('show');
            return;
        }
        window.chariowPopup = popup;

        // Démarrer le polling
        startPolling(reference);

    } catch (err) {
        showFormBlock();
        document.getElementById('err-global').textContent = 'Erreur réseau : ' + err.message;
        document.getElementById('err-global').classList.add('show');
    }
});

// ========== Polling ==========
async function checkPaymentStatus(reference) {
    const fd = new FormData();
    fd.append('action', 'check_payment');
    fd.append('reference', reference);
    const res = await fetch(API_ENDPOINT, { method: 'POST', body: fd });
    return await res.json();
}

function startPolling(reference) {
    stopPolling();
    pollStart = Date.now();
    currentRef = reference;

    // Vérification immédiate
    checkPaymentStatus(reference).then(data => {
        if (data.statut === 'confirme') { stopPolling(); onSuccess(reference); }
        else if (data.statut === 'echoue') { stopPolling(); onFailure(reference, data.message); }
    }).catch(() => {});

    pollTimer = setInterval(async () => {
        if (Date.now() - pollStart > 120000) { // 2 min timeout
            stopPolling();
            onTimeout();
            return;
        }
        try {
            const data = await checkPaymentStatus(reference);
            if (data.statut === 'confirme') { stopPolling(); onSuccess(reference); }
            else if (data.statut === 'echoue') { stopPolling(); onFailure(reference, data.message); }
        } catch (e) { console.warn('[Polling]', e); }
    }, 2000);
}

function stopPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    pollStart = null;
}

function onSuccess(reference) {
    showReceipt(reference, 'Confirmé');
    document.getElementById('rc-votes').textContent = selectedPack.votes;
    document.getElementById('rc-amount').textContent = selectedPack.usd + ' USD';
    document.getElementById('rc-email').textContent = emailInput.value.trim();
    document.getElementById('rc-date').textContent = new Date().toLocaleString('fr-FR');
    document.getElementById('rc-statut').textContent = 'Confirmé';
    updateVotesActuels();
    if (window.chariowPopup && !window.chariowPopup.closed) window.chariowPopup.close();
    sessionStorage.removeItem('chariow_pending_ref');
}

function onFailure(reference, message) {
    showReceipt(reference, 'Échoué');
    document.getElementById('rc-statut').textContent = 'Échoué';
    document.getElementById('rc-email').textContent = emailInput.value.trim();
    document.getElementById('rc-date').textContent = new Date().toLocaleString('fr-FR');
    if (window.chariowPopup && !window.chariowPopup.closed) window.chariowPopup.close();
    sessionStorage.removeItem('chariow_pending_ref');
}

function onTimeout() {
    showFormBlock();
    document.getElementById('err-global').textContent = 'Délai de vérification dépassé. Les votes seront crédités automatiquement dès confirmation.';
    document.getElementById('err-global').classList.add('show');
    sessionStorage.removeItem('chariow_pending_ref');
}

function showReceipt(reference, statut) {
    hideAllBlocks();
    receiptBlock.style.display = 'block';
    document.getElementById('rc-ref').textContent = reference;
    document.getElementById('rc-cand').textContent = CANDIDATE_NAME;
}

function hideAllBlocks() {
    document.querySelector('.card').style.display = 'none';
    loadingBlock.style.display = 'none';
    receiptBlock.style.display = 'none';
}
function showFormBlock() {
    hideAllBlocks();
    document.querySelector('.card').style.display = 'block';
}

// ========== Mise à jour des votes en temps réel ==========
async function updateVotesActuels() {
    const fd = new FormData();
    fd.append('action', 'get_realtime_votes');
    fd.append('evenement_id', CONCOURS_ID);
    const resp = await fetch(API_ENDPOINT, { method: 'POST', body: fd });
    const data = await resp.json();
    if (data.success && data.votes_per_candidate[CANDIDATE_ID] !== undefined) {
        document.getElementById('votes-actuels').textContent = data.votes_per_candidate[CANDIDATE_ID];
    }
}

// ========== Copier le lien ==========
copyLinkBtn.addEventListener('click', function() {
    const url = window.location.href;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(() => {
            copyFeedback.style.display = 'block';
            setTimeout(() => { copyFeedback.style.display = 'none'; }, 3000);
        }).catch(() => fallbackCopy(url));
    } else {
        fallbackCopy(url);
    }
});

function fallbackCopy(text) {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    document.body.appendChild(textarea);
    textarea.select();
    try {
        document.execCommand('copy');
        copyFeedback.style.display = 'block';
        setTimeout(() => { copyFeedback.style.display = 'none'; }, 3000);
    } catch (e) {
        alert('Impossible de copier le lien. Veuillez le copier manuellement.');
    }
    document.body.removeChild(textarea);
}

// ========== Classement (onglets) ==========
const tabs = document.querySelectorAll('.tab-btn');
const periods = document.querySelectorAll('.class-period');
tabs.forEach(btn => {
    btn.addEventListener('click', () => {
        tabs.forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        const periode = btn.dataset.periode;
        periods.forEach(p => p.style.display = p.dataset.periode === periode ? 'block' : 'none');
    });
});

// ========== Nav shadow ==========
window.addEventListener('scroll', () => {
    document.getElementById('nav').classList.toggle('shadow', window.scrollY > 50);
}, { passive: true });

// ========== Reprise du polling si une référence est en attente ==========
document.addEventListener('DOMContentLoaded', () => {
    const pendingRef = sessionStorage.getItem('chariow_pending_ref');
    if (pendingRef) {
        sessionStorage.removeItem('chariow_pending_ref');
        // Réafficher le loading et relancer le polling
        hideAllBlocks();
        loadingBlock.style.display = 'block';
        loadingMsg.textContent = 'Reprise du paiement…';
        startPolling(pendingRef);
    }
});
</script>
</body>
</html>