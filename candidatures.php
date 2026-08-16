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

// Récupération du site correspondant au sous-domaine
$stmtSite = $pdo->prepare("SELECT site_id, nom_entreprise, logo_concours, logo_extension, lien_unique FROM sites WHERE lien_unique = ?");
$stmtSite->execute([$subdomain]);
$siteData = $stmtSite->fetch();

// Fallback si aucun site trouvé pour ce sous-domaine : prendre le premier site
if (!$siteData) {
    $stmtFallback = $pdo->query("SELECT site_id, nom_entreprise, logo_concours, logo_extension, lien_unique FROM sites LIMIT 1");
    $siteData = $stmtFallback->fetch();
    if (!$siteData) {
        die("Aucun site trouvé.");
    }
    $subdomain = $siteData['lien_unique'];
}

$siteId            = $siteData['site_id'];
$siteName          = $siteData['nom_entreprise'];
$siteLogoConcours  = $siteData['lien_unique'];          // colonne correcte
$siteLogoExtension = $siteData['logo_extension'];
$siteLien          = $siteData['lien_unique'];

define('STOCKAGE_DOMAIN', 'https://gestion.zaloriatech.com');

// Construction de l'URL du logo du site (dossier sites_logo)
$logoUrl = '';
if ($siteLogoConcours && $siteLogoExtension) {
    $logoUrl = STOCKAGE_DOMAIN . '/admin/uploads/sites_logo/' . $siteLogoConcours . '.' . $siteLogoExtension;
}

// ========== Liste des concours disponibles pour ce site ==========
$allConcours = $pdo->prepare("SELECT concours_id, nom_concours FROM concours WHERE site_id = ? ORDER BY date_ouverture DESC");
$allConcours->execute([$siteId]);
$concoursDisponibles = $allConcours->fetchAll();

// ========== Concours sélectionné (GET, ou actif par défaut) ==========
$selectedConcoursId = isset($_GET['concours_id']) ? (int)$_GET['concours_id'] : 0;

// Recherche d'un concours actif pour le site
$stmtActif = $pdo->prepare("
    SELECT concours_id, nom_concours, url_concours, logo_concours, logo_extension,
             date_ouverture, date_cloture, etat_concours,
           site_id, arret_manuel, cree_le, modifie_le, results_visible,
           verification_active, results_live
    FROM concours
    WHERE site_id = ? AND etat_concours = 'actif' AND arret_manuel = 0
      AND NOW() BETWEEN date_ouverture AND date_cloture
    ORDER BY date_ouverture ASC
    LIMIT 1
");
$stmtActif->execute([$siteId]);
$concoursActif = $stmtActif->fetch();

// Si un concours spécifique est demandé, on le vérifie
if ($selectedConcoursId > 0) {
    $stmtC = $pdo->prepare("SELECT * FROM concours WHERE concours_id = ? AND site_id = ?");
    $stmtC->execute([$selectedConcoursId, $siteId]);
    $currentConcours = $stmtC->fetch();
    if (!$currentConcours) {
        $selectedConcoursId = 0; // invalide, on repassera au défaut
    }
}

// Si aucun concours sélectionné valide, on prend le concours actif ou le premier disponible
if ($selectedConcoursId == 0) {
    if ($concoursActif) {
        $currentConcours = $concoursActif;
        $selectedConcoursId = $concoursActif['concours_id'];
    } elseif (!empty($concoursDisponibles)) {
        // fallback : premier concours de la liste (même inactif)
        $currentConcours = $pdo->query("SELECT * FROM concours WHERE concours_id = " . (int)$concoursDisponibles[0]['concours_id'])->fetch();
        $selectedConcoursId = $concoursDisponibles[0]['concours_id'];
    } else {
        die("Aucun concours disponible.");
    }
}

$concours_id = $selectedConcoursId;

// ========== Traitement du formulaire (AJAX) ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_candidature'])) {
    header('Content-Type: application/json');
    try {
        $nom = trim($_POST['nom'] ?? '');
        $postnom = trim($_POST['postnom'] ?? '');
        $prenom = trim($_POST['prenom'] ?? '');
        $age = intval($_POST['age'] ?? 0);
        $taille = floatval($_POST['taille'] ?? 0);
        $profession = trim($_POST['profession'] ?? '');
        if ($profession === 'Autre') {
            $profession = trim($_POST['profession_autre'] ?? '');
        }
        $email = trim($_POST['email'] ?? '');
        $telephone = trim($_POST['telephone'] ?? '');
        $adresse = trim($_POST['adresse'] ?? '');
        $province = trim($_POST['province'] ?? '');
        $ville = trim($_POST['ville'] ?? '');
        $niveau_education = trim($_POST['niveau_education'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        $cb1 = isset($_POST['f_cb1']) ? 1 : 0;
        $cb2 = isset($_POST['f_cb2']) ? 1 : 0;
        $cb3 = isset($_POST['f_cb3']) ? 1 : 0;
        $photoFile = $_FILES['photo'] ?? null;
        $concoursPostId = intval($_POST['concours_id'] ?? $concours_id);
        $sitePostId = intval($_POST['site_id'] ?? $siteId);

        // Validation
        if (empty($nom) || empty($postnom) || empty($prenom) || $age < 18 || $age > 28 || $taille < 160 || empty($email) || empty($telephone) || empty($province) || empty($ville) || empty($niveau_education) || !$cb1 || !$cb2 || !$cb3) {
            echo json_encode(['success' => false, 'message' => 'Veuillez remplir tous les champs obligatoires.']);
            exit;
        }

        if (!$photoFile || $photoFile['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Veuillez ajouter une photo de profil.']);
            exit;
        }
        if ($photoFile['size'] > 5 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'La photo dépasse 5 Mo.']);
            exit;
        }

        // Upload photo -> dossier candidature_participantes
        $uploadDir = __DIR__ . '/admin/uploads/candidature_participantes/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $extension = strtolower(pathinfo($photoFile['name'], PATHINFO_EXTENSION));
        $photoName = 'candidate_' . date('YmdHis') . '_' . uniqid() . '.' . $extension;
        $photoPath = 'admin/uploads/candidature_participantes/' . $photoName;
        if (!move_uploaded_file($photoFile['tmp_name'], $uploadDir . $photoName)) {
            echo json_encode(['success' => false, 'message' => 'Erreur lors de l\'upload de la photo.']);
            exit;
        }

        // Génération code participant unique (par site)
        $code = 'C' . date('Y') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $stmtCode = $pdo->prepare("SELECT 1 FROM candidature_participante WHERE code_participante = ? AND site_id = ?");
        $stmtCode->execute([$code, $sitePostId]);
        while ($stmtCode->fetchColumn()) {
            $code = 'C' . date('Y') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $stmtCode->execute([$code, $sitePostId]);
        }

        $nom_complet = $nom . ' ' . $postnom . ' ' . $prenom;
        $biographie = "Profession : $profession\nAdresse : $adresse\nEmail : $email\nTéléphone : $telephone\n\n" . $bio;

        // Insertion dans candidature_participante (avec site_id et photo_candidature)
        $stmt = $pdo->prepare("
            INSERT INTO candidature_participante 
            (concours_id, site_id, code_participante, nom_complet, age, ville_origine, 
             niveau_etudes, taille_en_cm, biographie, cause_soutenue, 
             photo_candidature, situation_actuelle, inscrite_le, modifie_le)
            VALUES 
            (:concours_id, :site_id, :code, :nom_complet, :age, :ville, 
             :niveau_etudes, :taille, :biographie, :cause, 
             :photo, 'active', NOW(), NOW())
        ");
        $stmt->execute([
            ':concours_id'     => $concoursPostId,
            ':site_id'         => $sitePostId,
            ':code'            => $code,
            ':nom_complet'     => $nom_complet,
            ':age'             => $age,
            ':ville'           => $ville,
            ':niveau_etudes'   => $niveau_education,
            ':taille'          => $taille,
            ':biographie'      => $biographie,
            ':cause'           => '',
            ':photo'           => $photoPath
        ]);

        echo json_encode(['success' => true, 'message' => 'Candidature envoyée avec succès !']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erreur serveur : ' . $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscription — <?= htmlspecialchars($siteName) ?></title>
    <link rel="icon" type="image/png" href="<?= $logoUrl ?>">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,300;1,400;1,500&family=Jost:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ===== RESET & BASE ===== */
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        html{scroll-behavior:smooth}
        body{overflow-x:hidden;-webkit-font-smoothing:antialiased}
        ::-webkit-scrollbar{width:3px}
        ::-webkit-scrollbar-track{background:#0E0A06}
        ::-webkit-scrollbar-thumb{background:rgba(212,168,67,.32);border-radius:4px}
        :root{
          --gold:#C4991A;--gold-lt:#E8C050;--gold-dk:#8B6B0C;--gold-pale:#F5E4A8;
          --cream:#FAF8F4;--cream2:#F3EFE6;--cream3:#EAE4D5;--ivory:#FFFDF8;
          --ink:#201C15;--ink2:#4A4437;--ink3:#7A7264;--ink4:#AEA89C;
          --border:#E0D8C8;--border2:#CFC5AD;
          --red:#B83232;--green:#2A7A4F;
          --shadow:rgba(196,153,26,.13);
          --ff:'Jost',sans-serif;--fs:'Cormorant Garamond',serif;
          --ease:cubic-bezier(.22,1,.36,1);
        }
        .insc-page{background:var(--cream);font-family:var(--ff);color:var(--ink);overflow-x:hidden;min-height:100vh}

        /* TOP BAR */
        .top-bar{position:fixed;top:0;left:0;right:0;z-index:100;background:rgba(255,255,255,0.85);backdrop-filter:blur(12px);padding:10px 24px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 4px 20px rgba(0,0,0,0.06);border-bottom:1px solid var(--border2)}
        .top-logo{display:flex;align-items:center;gap:12px}
        .top-logo img{height:38px;width:auto;border-radius:50%;border:1px solid var(--gold);padding:2px;background:white}
        .top-logo span{font-family:var(--fs);font-size:1.2rem;font-weight:500;color:var(--ink);letter-spacing:0.02em}
        .top-home{font-size:0.8rem;font-weight:600;color:var(--gold-dk);text-decoration:none;display:flex;align-items:center;gap:6px;transition:opacity 0.2s}
        .top-home:hover{opacity:0.75}
        .top-home svg{width:16px;height:16px}

        /* HERO */
        .hero{position:relative;height:100vh;min-height:600px;max-height:900px;overflow:hidden;padding-top:70px}
        .slide{position:absolute;inset:0;background-size:cover;background-position:center;opacity:0;transform:scale(1.07);transition:opacity 1.4s cubic-bezier(.4,0,.2,1),transform 7s ease;z-index:0}
        .slide.active{opacity:1;transform:scale(1);z-index:1}
        .slide.exit{opacity:0;transform:scale(1.04);z-index:2;transition:opacity 1.3s cubic-bezier(.4,0,.2,1),transform 1.3s ease}
        .s1{background-image:url('https://images.unsplash.com/photo-1531746020798-e6953c6e8e04?w=1600&q=80')}
        .s2{background-image:url('https://images.unsplash.com/photo-1524504388940-b1c1722653e1?w=1600&q=80')}
        .s3{background-image:url('https://images.unsplash.com/photo-1488426862026-3ee34a7d66df?w=1600&q=80')}
        .s4{background-image:url('https://images.unsplash.com/photo-1509631179647-0177331693ae?w=1600&q=80')}
        .s5{background-image:url('https://images.unsplash.com/photo-1487412720507-e7ab37603c6f?w=1600&q=80')}
        .hero-ov{position:absolute;inset:0;z-index:3;background:linear-gradient(to bottom,rgba(18,13,7,.18) 0%,rgba(18,13,7,.05) 28%,rgba(18,13,7,.42) 62%,rgba(18,13,7,.82) 100%)}
        .hero-vline{position:absolute;left:52px;top:14%;bottom:14%;z-index:4;width:1px;background:linear-gradient(to bottom,transparent,rgba(232,192,80,.55) 30%,rgba(232,192,80,.55) 70%,transparent);animation:vl 1.1s var(--ease) .5s both;transform-origin:top}
        @keyframes vl{from{transform:scaleY(0);opacity:0}to{transform:scaleY(1);opacity:1}}
        .hero-content{position:absolute;inset:0;z-index:5;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:180px 80px 24px;text-align:center}
        .h-badge{display:inline-flex;align-items:center;gap:10px;padding:7px 20px;border:1px solid rgba(232,192,80,.38);border-radius:100px;background:rgba(232,192,80,.09);backdrop-filter:blur(6px);margin-bottom:20px;animation:fu .8s var(--ease) .35s both}
        .h-badge-dot{width:6px;height:6px;border-radius:50%;background:var(--gold-lt);animation:bk 2.2s ease infinite}
        @keyframes bk{0%,100%{opacity:1}50%{opacity:.25}}
        .h-badge span{font-size:.62rem;font-weight:700;letter-spacing:.28em;text-transform:uppercase;color:var(--gold-pale)}
        .h-title{font-family:var(--fs);font-size:clamp(2.8rem,7vw,5.2rem);font-weight:300;line-height:1.04;color:#FFFCF0;margin-bottom:16px;animation:fu .9s var(--ease) .5s both;text-shadow:0 4px 40px rgba(0,0,0,.35)}
        .h-title em{font-style:italic;color:var(--gold-pale)}
        .h-sub{font-size:.9rem;font-weight:300;color:rgba(255,252,240,.66);max-width:480px;line-height:1.8;margin-bottom:32px;animation:fu .9s var(--ease) .65s both}
        .h-actions{display:flex;gap:12px;flex-wrap:wrap;justify-content:center;animation:fu .9s var(--ease) .8s both}
        .btn-main{display:inline-flex;align-items:center;gap:9px;padding:14px 34px;border-radius:5px;background:linear-gradient(130deg,var(--gold-dk),var(--gold),var(--gold-lt));border:none;font-family:var(--ff);font-size:.78rem;font-weight:700;color:#180D00;letter-spacing:.07em;text-transform:uppercase;cursor:pointer;text-decoration:none;box-shadow:0 6px 28px rgba(196,153,26,.45);transition:all .25s var(--ease)}
        .btn-main:hover{transform:translateY(-3px);box-shadow:0 12px 40px rgba(196,153,26,.55)}
        .btn-main svg{width:15px;height:15px}
        .btn-ghost{display:inline-flex;align-items:center;gap:8px;padding:13px 28px;border-radius:5px;background:rgba(255,255,255,.09);border:1px solid rgba(255,255,255,.28);backdrop-filter:blur(8px);font-family:var(--ff);font-size:.78rem;font-weight:500;color:rgba(255,252,240,.85);letter-spacing:.04em;cursor:pointer;text-decoration:none;transition:all .25s}
        .btn-ghost:hover{background:rgba(255,255,255,.18)}
        .btn-ghost svg{width:14px;height:14px}
        .h-dots{position:absolute;bottom:28px;right:32px;z-index:6;display:flex;gap:7px;align-items:center}
        .h-dot{width:6px;height:6px;border-radius:50%;background:rgba(255,255,255,.28);cursor:pointer;transition:all .3s}
        .h-dot.on{background:var(--gold-lt);width:22px;border-radius:3px}
        .h-counter{position:absolute;top:34px;right:40px;z-index:6;font-family:var(--fs);font-size:.85rem;font-weight:300;color:rgba(255,252,240,.45);animation:fi 1s var(--ease) 1s both;letter-spacing:.05em}
        .h-counter strong{color:rgba(255,252,240,.82);font-weight:400}
        .h-event{position:absolute;top:34px;left:52px;z-index:6;animation:fi 1s var(--ease) .8s both;display:flex;align-items:center;gap:12px}
        .h-flag{width:36px;height:36px;border-radius:50%;border:1.5px solid rgba(232,192,80,.38);background:rgba(18,13,7,.5);backdrop-filter:blur(6px);display:flex;align-items:center;justify-content:center;font-size:1.1rem}
        .h-evtxt{display:flex;flex-direction:column;gap:2px}
        .h-evtxt span:first-child{font-size:.6rem;font-weight:700;letter-spacing:.22em;text-transform:uppercase;color:var(--gold-lt)}
        .h-evtxt span:last-child{font-size:.68rem;color:rgba(255,252,240,.45);font-weight:300}
        .h-scroll{position:absolute;bottom:28px;left:52px;z-index:6;display:flex;flex-direction:column;align-items:center;gap:8px;animation:fi .8s var(--ease) 1.2s both}
        .h-scroll span{font-size:.55rem;font-weight:700;letter-spacing:.28em;text-transform:uppercase;color:rgba(255,252,240,.32);writing-mode:vertical-lr}
        .scroll-bar{width:1px;height:44px;background:rgba(255,255,255,.1);position:relative;overflow:hidden;border-radius:1px}
        .scroll-bar::after{content:'';position:absolute;top:-16px;left:0;right:0;height:16px;background:linear-gradient(to bottom,transparent,var(--gold-lt));animation:sa 1.8s ease infinite}
        @keyframes sa{to{top:44px}}
        @keyframes fu{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:none}}
        @keyframes fi{from{opacity:0}to{opacity:1}}

        /* BAND */
        .band{background:var(--gold);height:44px;overflow:hidden;display:flex}
        .band-track{display:flex;animation:bs 22s linear infinite;white-space:nowrap;flex-shrink:0}
        @keyframes bs{from{transform:translateX(0)}to{transform:translateX(-50%)}}
        .bi{display:inline-flex;align-items:center;gap:10px;padding:0 32px;height:44px;font-size:.62rem;font-weight:700;letter-spacing:.22em;text-transform:uppercase;color:#1A0D00;border-right:1px solid rgba(26,13,0,.12);flex-shrink:0}
        .bi svg{width:12px;height:12px;opacity:.7;flex-shrink:0}

        /* SÉLECTEUR CONCOURS */
        .concours-selector{max-width:1100px;margin:30px auto 0;padding:0 24px;display:flex;align-items:center;gap:16px}
        .concours-selector label{font-size:.78rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--ink3);white-space:nowrap}
        .concours-selector select{background:var(--ivory);border:1.5px solid var(--border);border-radius:8px;padding:10px 36px 10px 14px;font-family:var(--ff);font-size:.84rem;color:var(--ink);appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8' fill='none'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%237A7264' stroke-width='1.5' stroke-linecap='round'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 14px center;background-color:var(--ivory);transition:border-color .2s}
        .concours-selector select:focus{border-color:var(--gold);outline:none;box-shadow:0 0 0 3px rgba(196,153,26,.1)}

        /* CRITÈRES */
        .sec-crit{background:var(--ivory);padding:80px 24px 90px}
        .sec-crit-in{max-width:1100px;margin:0 auto}
        .crit-hd{display:flex;flex-direction:column;align-items:center;text-align:center;margin-bottom:60px}
        .crit-kick{display:inline-flex;align-items:center;gap:10px;font-size:.62rem;font-weight:700;letter-spacing:.3em;text-transform:uppercase;color:var(--gold);margin-bottom:16px}
        .crit-kick::before,.crit-kick::after{content:'';width:28px;height:1px;background:linear-gradient(90deg,transparent,var(--gold))}
        .crit-kick::after{transform:scaleX(-1)}
        .crit-h2{font-family:var(--fs);font-size:clamp(1.8rem,3.5vw,2.8rem);font-weight:400;line-height:1.15;color:var(--ink);margin-bottom:12px}
        .crit-h2 em{font-style:italic;color:var(--gold)}
        .crit-desc{font-size:.88rem;color:var(--ink3);line-height:1.75;max-width:460px}
        .crit-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:18px}
        @media(min-width:640px){.crit-grid{grid-template-columns:repeat(3,1fr)}}
        @media(min-width:960px){.crit-grid{grid-template-columns:repeat(4,1fr)}}
        .crit-card{position:relative;border-radius:16px;overflow:hidden;background:var(--cream2);border:1px solid var(--border);aspect-ratio:3/3.8;display:flex;flex-direction:column;transition:transform .3s var(--ease),box-shadow .3s var(--ease);cursor:default}
        .crit-card:hover{transform:translateY(-5px);box-shadow:0 20px 50px rgba(196,153,26,.15)}
        .crit-card-img{position:absolute;inset:0;background-size:cover;background-position:center top;transition:transform .6s var(--ease)}
        .crit-card:hover .crit-card-img{transform:scale(1.05)}
        .crit-card-ov{position:absolute;inset:0;background:linear-gradient(to bottom,rgba(18,13,7,.06) 0%,rgba(18,13,7,.22) 40%,rgba(18,13,7,.84) 100%);z-index:1}
        .crit-card-body{position:relative;z-index:2;margin-top:auto;padding:20px 16px 18px}
        .crit-card-ico{width:38px;height:38px;border-radius:10px;background:rgba(232,192,80,.18);border:1px solid rgba(232,192,80,.3);display:flex;align-items:center;justify-content:center;margin-bottom:10px;backdrop-filter:blur(4px)}
        .crit-card-ico svg{width:18px;height:18px}
        .crit-card-lbl{font-size:.58rem;font-weight:700;letter-spacing:.2em;text-transform:uppercase;color:rgba(245,228,168,.6);margin-bottom:4px}
        .crit-card-val{font-family:var(--fs);font-size:1.1rem;font-weight:400;color:#FFFCF0;line-height:1.2}
        .crit-card-sub{font-size:.66rem;color:rgba(255,252,240,.48);font-weight:300;margin-top:3px;line-height:1.5}
        .crit-check{position:absolute;top:13px;right:13px;z-index:3;width:26px;height:26px;border-radius:50%;background:rgba(42,122,79,.7);border:1px solid rgba(42,122,79,.9);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center}
        .crit-check svg{width:12px;height:12px}
        .ci-age{background-image:url('https://images.unsplash.com/photo-1531746020798-e6953c6e8e04?w=600&q=75')}
        .ci-nat{background-image:url('https://images.unsplash.com/photo-1571019613454-1cb2f99b2d8b?w=600&q=75')}
        .ci-tal{background-image:url('https://images.unsplash.com/photo-1509631179647-0177331693ae?w=600&q=75')}
        .ci-edu{background-image:url('https://images.unsplash.com/photo-1523050854058-8df90110c9f1?w=600&q=75')}
        .ci-cel{background-image:url('https://images.unsplash.com/photo-1488426862026-3ee34a7d66df?w=600&q=75')}
        .ci-cas{background-image:url('https://images.unsplash.com/photo-1589829545856-d10d557cf95f?w=600&q=75')}
        .ci-dis{background-image:url('https://images.unsplash.com/photo-1436491865332-7a61a109cc05?w=600&q=75')}
        .ci-eng{background-image:url('https://images.unsplash.com/photo-1559027615-cd4628902d4a?w=600&q=75')}

        /* FORM */
        .sec-form{background:var(--cream);padding:60px 24px 60px}
        .fsep{max-width:1100px;margin:0 auto 50px;display:flex;align-items:center;gap:20px}
        .fsep-line{flex:1;height:1px;background:var(--border2)}
        .fsep-mid{display:flex;flex-direction:column;align-items:center;gap:8px;flex-shrink:0}
        .fsep-ico{width:46px;height:46px;border-radius:50%;background:var(--ivory);border:1px solid var(--border2);display:flex;align-items:center;justify-content:center}
        .fsep-ico svg{width:18px;height:18px}
        .fsep-lbl{font-size:.62rem;font-weight:700;letter-spacing:.28em;text-transform:uppercase;color:var(--gold)}
        .flayout{max-width:1100px;margin:0 auto;display:grid;grid-template-columns:1.5fr .5fr;gap:24px;align-items:start}
        @media(max-width:1023px){.flayout{grid-template-columns:1fr}}
        .col-left,.col-right{display:flex;flex-direction:column;gap:20px}
        @media(max-width:430px){.col-left,.col-right{width:90%}}
        .fc{background:var(--ivory);border:1px solid var(--border);border-radius:16px;overflow:hidden;box-shadow:0 2px 14px var(--shadow);transition:border-color .25s,box-shadow .25s}
        .fc:hover{border-color:var(--border2);box-shadow:0 6px 32px var(--shadow)}
        .fc-hd{padding:15px 22px;background:var(--cream2);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:11px}
        .fc-hd-ic{width:34px;height:34px;border-radius:9px;background:rgba(196,153,26,.1);border:1px solid rgba(196,153,26,.18);display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .fc-hd-ic svg{width:16px;height:16px}
        .fc-hd-t{font-size:.8rem;font-weight:600;color:var(--gold-dk);letter-spacing:.04em}
        .fc-hd-s{font-size:.68rem;color:var(--ink4);margin-left:auto}
        .fc-body{padding:22px;display:flex;flex-direction:column;gap:16px}
        .frow{display:grid;grid-template-columns:1fr 1fr;gap:14px}
        @media(max-width:560px){.frow{grid-template-columns:1fr}}
        .field{display:flex;flex-direction:column;gap:5px}
        .field label{font-size:.67rem;font-weight:700;color:var(--ink3);letter-spacing:.08em;text-transform:uppercase;display:flex;align-items:center;gap:3px}
        .field label em{color:var(--red);font-style:normal}
        .field input,.field select,.field textarea{width:100%;background:var(--cream);border:1.5px solid var(--border);border-radius:8px;padding:11px 14px;font-family:var(--ff);font-size:.84rem;color:var(--ink);outline:none;transition:border-color .2s,background .2s,box-shadow .2s}
        .field input::placeholder,.field textarea::placeholder{color:var(--ink4)}
        .field input:focus,.field select:focus,.field textarea:focus{border-color:var(--gold);background:var(--ivory);box-shadow:0 0 0 3px rgba(196,153,26,.1)}
        .field input:hover:not(:focus),.field select:hover:not(:focus){border-color:var(--border2)}
        .field select{appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8' fill='none'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%237A7264' stroke-width='1.5' stroke-linecap='round'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 14px center;padding-right:36px;background-color:var(--cream)}
        .field select option{background:var(--ivory);color:var(--ink)}
        .field textarea{min-height:100px;resize:vertical;line-height:1.7}
        .err{font-size:.64rem;color:var(--red);display:none;font-weight:500;padding:1px 0 0 2px}
        .field.has-err .err{display:block}
        .field.has-err input,.field.has-err select,.field.has-err textarea{border-color:var(--red);background:rgba(184,50,50,.03);box-shadow:0 0 0 3px rgba(184,50,50,.07)}
        .field.ok input,.field.ok select{border-color:rgba(42,122,79,.45)}
        .chk-list{display:flex;flex-direction:column;gap:8px;padding-top:4px}
        .chk-row{display:flex;align-items:flex-start;gap:12px;padding:12px 16px;border:1.5px solid var(--border);border-radius:10px;background:var(--cream2);cursor:pointer;transition:all .2s}
        .chk-row:hover{border-color:rgba(196,153,26,.35);background:rgba(196,153,26,.04)}
        .chk-row input[type=checkbox]{accent-color:var(--gold);width:16px;height:16px;margin-top:1px;flex-shrink:0;cursor:pointer}
        .chk-row-t{font-size:.78rem;color:var(--ink2);line-height:1.55}
        .chk-row-t strong{color:var(--gold-dk);font-weight:600}
        .fc-ft{padding:15px 22px;border-top:1px solid var(--border);background:var(--cream2);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
        .ft-note{font-size:.65rem;color:var(--ink4);display:flex;align-items:center;gap:5px}
        .ft-note svg{width:11px;height:11px;flex-shrink:0}
        .ft-actions{display:flex;align-items:center;gap:10px}
        .btn-cancel{padding:10px 22px;border-radius:7px;background:transparent;border:1.5px solid var(--border2);font-family:var(--ff);font-size:.75rem;font-weight:600;color:var(--ink2);cursor:pointer;transition:all .2s;text-decoration:none;display:inline-flex;align-items:center;gap:7px}
        .btn-cancel:hover{background:var(--cream3);color:var(--ink)}
        .btn-cancel svg{width:14px;height:14px}
        .btn-sub{position:relative;display:inline-flex;align-items:center;gap:9px;padding:11px 28px;border-radius:7px;background:linear-gradient(130deg,var(--gold-dk),var(--gold),var(--gold-lt));border:none;font-family:var(--ff);font-size:.78rem;font-weight:700;color:#180D00;cursor:pointer;transition:all .25s var(--ease);letter-spacing:.05em;text-transform:uppercase;box-shadow:0 4px 18px rgba(196,153,26,.32)}
        .btn-sub:hover{transform:translateY(-2px);box-shadow:0 8px 28px rgba(196,153,26,.45)}
        .btn-sub:active{transform:translateY(0)}
        .btn-sub:disabled{opacity:.55;cursor:not-allowed;transform:none}
        .btn-sub svg{width:15px;height:15px}
        .spin{display:none;width:15px;height:15px;border:2px solid rgba(24,13,0,.2);border-top-color:#180D00;border-radius:50%;animation:sp .65s linear infinite}
        .btn-sub.loading .spin{display:block}
        .btn-sub.loading #btnTxt{opacity:.45}
        @keyframes sp{to{transform:rotate(360deg)}}

        /* PHOTO UPLOAD */
        .pdrop{border:2px dashed var(--border2);border-radius:12px;padding:32px 20px;text-align:center;cursor:pointer;transition:all .25s;background:var(--cream2);position:relative;overflow:hidden}
        .pdrop:hover,.pdrop.over{border-color:var(--gold);background:rgba(196,153,26,.05)}
        .pdrop input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;z-index:2}
        .pd-ico{width:50px;height:50px;border-radius:13px;background:rgba(196,153,26,.1);border:1px solid rgba(196,153,26,.2);display:flex;align-items:center;justify-content:center;margin:0 auto 12px}
        .pd-ico svg{width:22px;height:22px}
        .pd-lbl{font-size:.8rem;color:var(--ink2);line-height:1.6}
        .pd-lbl strong{color:var(--gold-dk)}
        .pd-hint{font-size:.62rem;color:var(--ink4);margin-top:5px}
        .pprev{display:none;position:relative;border-radius:10px;overflow:hidden}
        .pprev img{width:100%;display:block;border-radius:10px}
        .pprev .rm{position:absolute;top:8px;right:8px;width:28px;height:28px;border-radius:50%;background:rgba(184,50,50,.88);border:none;color:#fff;font-size:.7rem;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:transform .2s;z-index:3}
        .pprev .rm:hover{transform:scale(1.1)}
        #pinfo{font-size:.68rem;color:var(--ink4);padding:6px 0;text-align:center}
        .cnote{font-size:.62rem;color:var(--gold-dk);background:rgba(196,153,26,.06);border:1px solid rgba(196,153,26,.15);border-radius:7px;padding:7px 12px;text-align:center;display:flex;align-items:center;justify-content:center;gap:5px}
        .cnote svg{width:11px;height:11px;flex-shrink:0}

        /* TIPS */
        .tip-r{display:flex;gap:11px;align-items:flex-start;padding:10px 0;border-bottom:1px solid var(--border)}
        .tip-r:last-child{border-bottom:none;padding-bottom:0}
        .t-ic{width:30px;height:30px;min-width:30px;border-radius:8px;background:rgba(196,153,26,.08);border:1px solid rgba(196,153,26,.15);display:flex;align-items:center;justify-content:center;margin-top:1px}
        .t-ic svg{width:13px;height:13px}
        .t-tx{font-size:.74rem;color:var(--ink2);line-height:1.6}
        .ct-r{display:flex;gap:11px;align-items:center;padding:10px 0;border-bottom:1px solid var(--border)}
        .ct-r:last-child{border-bottom:none;padding-bottom:0}
        .ct-ic{width:30px;height:30px;min-width:30px;border-radius:8px;background:rgba(196,153,26,.08);border:1px solid rgba(196,153,26,.15);display:flex;align-items:center;justify-content:center}
        .ct-ic svg{width:13px;height:13px}
        .ct-v{font-size:.75rem;color:var(--gold-dk);font-weight:500}

        /* SUCCESS */
        .insc-success{display:none;text-align:center;padding:100px 24px;max-width:520px;margin:0 auto}
        .insc-success.show{display:block}
        .s-ring{width:76px;height:76px;border-radius:50%;background:rgba(42,122,79,.08);border:1.5px solid rgba(42,122,79,.25);display:flex;align-items:center;justify-content:center;margin:0 auto 26px;animation:pop .6s var(--ease) both}
        .s-ring svg{width:34px;height:34px}
        @keyframes pop{from{transform:scale(.6);opacity:0}to{transform:scale(1);opacity:1}}
        .insc-success h2{font-family:var(--fs);font-style:italic;font-size:2rem;color:var(--ink);margin-bottom:12px;font-weight:400}
        .insc-success p{font-size:.87rem;color:var(--ink2);line-height:1.8;margin-bottom:32px}
        .btn-back{display:inline-flex;align-items:center;gap:8px;padding:13px 30px;border-radius:7px;background:linear-gradient(130deg,var(--gold-dk),var(--gold));color:#180D00;font-family:var(--ff);font-size:.78rem;font-weight:700;text-decoration:none;transition:all .25s;letter-spacing:.05em;text-transform:uppercase;box-shadow:0 4px 18px rgba(196,153,26,.32)}
        .btn-back:hover{transform:translateY(-2px);box-shadow:0 8px 28px rgba(196,153,26,.45)}
        .btn-back svg{width:15px;height:15px}

        /* REVEAL */
        .rv{opacity:0;transform:translateY(20px);transition:opacity .7s var(--ease),transform .7s var(--ease)}
        .rv.on{opacity:1;transform:none}
        .rv.d1{transition-delay:.1s}.rv.d2{transition-delay:.18s}.rv.d3{transition-delay:.26s}.rv.d4{transition-delay:.34s}

        @media(max-width:600px){.h-event,.h-scroll,.hero-vline{display:none}.h-counter{top:18px;right:18px}.hero{padding-top:60px}}
    </style>
</head>
<body>

<div class="insc-page">

     

    <!-- HERO -->
    <section class="hero">
      <div class="slide s1 active"></div><div class="slide s2"></div><div class="slide s3"></div><div class="slide s4"></div><div class="slide s5"></div>
      <div class="hero-ov"></div><div class="hero-vline"></div>
      <div class="h-event">
        <?php if ($logoUrl): ?>
            <a href="index.php" class="top-home"><img src="<?= $logoUrl ?>" alt="" style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:1.5px solid rgba(232,192,80,.38);"></a>
        <?php else: ?>
            <div class="h-flag">🇨🇩</div>
        <?php endif; ?>
        <div class="h-evtxt"><span><?= htmlspecialchars($siteName) ?></span><span>République Démocratique du Congo</span></div>
      </div>
      <div class="h-counter" id="hcount"><strong>01</strong> / 05</div>
      <div class="hero-content">
        <div class="h-badge"><span class="h-badge-dot"></span><span>Candidatures ouvertes · 2026</span></div>
        <h1 class="h-title">Portez la beauté<br>de la <em>RDC</em><br>au monde entier</h1>
        <p class="h-sub">Rejoignez l'élite de la beauté congolaise et représentez votre pays sur la grande scène internationale <?= htmlspecialchars($siteName) ?> 2026.</p>
        <div class="h-actions">
          <button class="btn-main" onclick="document.getElementById('formWrap').scrollIntoView({behavior:'smooth'})"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Postuler maintenant</button>
          <a class="btn-ghost" href="#criteres"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 8 12 12 14 14"/></svg>Voir les critères</a>
        </div>
      </div>
      <div class="h-dots" id="hdots"><div class="h-dot on" data-i="0"></div><div class="h-dot" data-i="1"></div><div class="h-dot" data-i="2"></div><div class="h-dot" data-i="3"></div><div class="h-dot" data-i="4"></div></div>
      <div class="h-scroll"><div class="scroll-bar"></div><span>Scroll</span></div>
    </section>

    <!-- BAND -->
    <div class="band">
      <div class="band-track">
        <div class="bi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 17l-6.2 4.3 2.4-7.4L2 9.4h7.6z"/></svg>Candidatures ouvertes</div>  
        <div class="bi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>Inscription gratuite</div> 
        <div class="bi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>Kinshasa · RDC</div>
        <div class="bi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>Inscription gratuite</div>
        <div class="bi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>26 provinces représentées</div>
      </div>
    </div>

    <!-- SÉLECTEUR CONCOURS -->
    <div class="concours-selector">
        <label for="concoursSelect">Concours :</label>
        <select id="concoursSelect" onchange="location.href='?concours_id='+this.value">
            <?php foreach ($concoursDisponibles as $c): ?>
                <option value="<?= $c['concours_id'] ?>" <?= $c['concours_id'] == $concours_id ? 'selected' : '' ?>><?= htmlspecialchars($c['nom_concours']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- CRITÈRES -->
    <section id="criteres" class="sec-crit">
      <div class="sec-crit-in">
        <div class="crit-hd rv"><div class="crit-kick">Éligibilité</div><h2 class="crit-h2">Êtes-vous prête à<br><em>représenter la RDC ?</em></h2><p class="crit-desc">Vérifiez les conditions requises avant de soumettre votre candidature à <?= htmlspecialchars($siteName) ?> 2026.</p></div>
        <div class="crit-grid">
          <div class="crit-card rv d1"><div class="crit-card-img ci-age"></div><div class="crit-card-ov"></div><div class="crit-check"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg></div><div class="crit-card-body"><div class="crit-card-ico"><svg viewBox="0 0 24 24" fill="none" stroke="rgba(245,228,168,.9)" stroke-width="1.8"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div><div class="crit-card-lbl">Âge</div><div class="crit-card-val">18 – 28 ans</div><div class="crit-card-sub">À la date de la finale</div></div></div>
          <div class="crit-card rv d1"><div class="crit-card-img ci-nat"></div><div class="crit-card-ov"></div><div class="crit-check"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg></div><div class="crit-card-body"><div class="crit-card-ico"><svg viewBox="0 0 24 24" fill="none" stroke="rgba(245,228,168,.9)" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/></svg></div><div class="crit-card-lbl">Nationalité</div><div class="crit-card-val">Congolaise RDC</div><div class="crit-card-sub">Ou d'origine congolaise</div></div></div>
          <div class="crit-card rv d2"><div class="crit-card-img ci-tal"></div><div class="crit-card-ov"></div><div class="crit-check"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg></div><div class="crit-card-body"><div class="crit-card-ico"><svg viewBox="0 0 24 24" fill="none" stroke="rgba(245,228,168,.9)" stroke-width="1.8"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg></div><div class="crit-card-lbl">Taille</div><div class="crit-card-val">1 m 60 min.</div><div class="crit-card-sub">Mesurée sans chaussures</div></div></div>
          <div class="crit-card rv d2"><div class="crit-card-img ci-edu"></div><div class="crit-card-ov"></div><div class="crit-check"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg></div><div class="crit-card-body"><div class="crit-card-ico"><svg viewBox="0 0 24 24" fill="none" stroke="rgba(245,228,168,.9)" stroke-width="1.8"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg></div><div class="crit-card-lbl">Éducation</div><div class="crit-card-val">Diplôme d'État</div><div class="crit-card-sub">Baccalauréat minimum</div></div></div>
          <div class="crit-card rv d3"><div class="crit-card-img ci-cel"></div><div class="crit-card-ov"></div><div class="crit-check"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg></div><div class="crit-card-body"><div class="crit-card-ico"><svg viewBox="0 0 24 24" fill="none" stroke="rgba(245,228,168,.9)" stroke-width="1.8"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg></div><div class="crit-card-lbl">Statut</div><div class="crit-card-val">Célibataire</div><div class="crit-card-sub">Sans enfant, non enceinte</div></div></div>
          <div class="crit-card rv d3"><div class="crit-card-img ci-cas"></div><div class="crit-card-ov"></div><div class="crit-check"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg></div><div class="crit-card-body"><div class="crit-card-ico"><svg viewBox="0 0 24 24" fill="none" stroke="rgba(245,228,168,.9)" stroke-width="1.8"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div><div class="crit-card-lbl">Casier judiciaire</div><div class="crit-card-val">Vierge</div><div class="crit-card-sub">Sans antécédents</div></div></div>
          <div class="crit-card rv d4"><div class="crit-card-img ci-dis"></div><div class="crit-card-ov"></div><div class="crit-check"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg></div><div class="crit-card-body"><div class="crit-card-ico"><svg viewBox="0 0 24 24" fill="none" stroke="rgba(245,228,168,.9)" stroke-width="1.8"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg></div><div class="crit-card-lbl">Disponibilité</div><div class="crit-card-val">Voyages</div><div class="crit-card-sub">Nationaux &amp; internationaux</div></div></div>
          <div class="crit-card rv d4"><div class="crit-card-img ci-eng"></div><div class="crit-card-ov"></div><div class="crit-check"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg></div><div class="crit-card-body"><div class="crit-card-ico"><svg viewBox="0 0 24 24" fill="none" stroke="rgba(245,228,168,.9)" stroke-width="1.8"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div><div class="crit-card-lbl">Engagement</div><div class="crit-card-val">Vocation sociale</div><div class="crit-card-sub">Implication communautaire</div></div></div>
        </div>
      </div>
    </section>

    <!-- FORMULAIRE -->
    <section class="sec-form">
      <div class="fsep rv">
        <div class="fsep-line"></div>
        <div class="fsep-mid">
          <div class="fsep-ico">
            <svg viewBox="0 0 24 24" fill="none" stroke="rgba(196,153,26,.75)" stroke-width="1.8"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
          </div>
          <span class="fsep-lbl">Formulaire d'inscription</span>
        </div>
        <div class="fsep-line"></div>
      </div>

      <form id="addForm" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="concours_id" value="<?= $concours_id ?>">
        <input type="hidden" name="site_id" value="<?= $siteId ?>">
        <div class="flayout rv" id="formWrap">

          <!-- COLONNE GAUCHE -->
          <div class="col-left">
            <!-- Identité -->
            <div class="fc" id="tour-card-identite">
              <div class="fc-hd"><div class="fc-hd-ic"><svg viewBox="0 0 24 24" fill="none" stroke="rgba(196,153,26,.85)" stroke-width="1.8"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div><span class="fc-hd-t">Identité</span><span class="fc-hd-s">Informations personnelles</span></div>
              <div class="fc-body">
                <div class="frow">
                  <div class="field"><label>Nom <em>*</em></label><input type="text" name="nom" id="f_nom" placeholder="Votre nom"><span class="err" id="e_nom">Minimum 2 caractères</span></div>
                  <div class="field"><label>Post-nom <em>*</em></label><input type="text" name="postnom" id="f_pn" placeholder="Votre post-nom"><span class="err" id="e_pn">Champ requis</span></div>
                </div>
                <div class="frow">
                  <div class="field"><label>Prénom <em>*</em></label><input type="text" name="prenom" id="f_pr" placeholder="Votre prénom"><span class="err" id="e_pr">Champ requis</span></div>
                  <div class="field"><label>Âge <em>*</em></label><input type="number" name="age" id="f_age" placeholder="18 – 28" min="18" max="28"><span class="err" id="e_age">Entre 18 et 28 ans</span></div>
                </div>
                <div class="frow">
                  <div class="field"><label>Taille (cm) <em>*</em></label><input type="number" name="taille" id="f_tal" placeholder="160" min="160"><span class="err" id="e_tal">Minimum 160 cm</span></div>
                  <div class="field">
                    <label>Profession <em>*</em></label>
                    <select name="profession" id="f_prof">
                      <option value="">Sélectionnez votre profession</option>
                      <option value="Étudiante">Étudiante</option>
                      <option value="Mannequin">Mannequin</option>
                      <option value="Entrepreneuse">Entrepreneuse</option>
                      <option value="Fonctionnaire">Fonctionnaire</option>
                      <option value="Profession libérale">Profession libérale</option>
                      <option value="Sans emploi">Sans emploi</option>
                      <option value="Autre">Autre (préciser)</option>
                    </select>
                    <span class="err" id="e_prof">Veuillez sélectionner une option</span>
                  </div>
                </div>
                <div class="field" id="autreProfField" style="display:none;">
                  <label>Précisez votre profession</label>
                  <input type="text" name="profession_autre" id="f_prof_autre" placeholder="Ex: Artiste, Coiffeuse...">
                </div>

                <div id="photo-dans-identite" style="margin-top:8px;">
                  <div class="pdrop" id="pDrop">
                    <input type="file" name="photo" id="pInput" accept="image/jpeg,image/png,image/webp,image/gif">
                    <div id="pHolder"><div class="pd-ico"><svg viewBox="0 0 24 24" fill="none" stroke="rgba(196,153,26,.8)" stroke-width="1.7"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg></div><div class="pd-lbl">Glissez une photo de profil ici<br>ou <strong>cliquez pour parcourir</strong></div><div class="pd-hint">JPG, PNG, WEBP · max 5 Mo</div></div>
                  </div>
                  <div class="pprev" id="pPrev"><img id="pImg" src="" alt="Aperçu"><button type="button" class="rm" id="pRm">✕</button></div>
                  <div id="pinfo"></div>
                  <div class="cnote"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>Compression auto · 800 px · JPEG 75 %</div>
                </div>
              </div>
            </div>

            <!-- Contact & Localisation -->
            <div class="fc" id="tour-card-contact">
              <div class="fc-hd"><div class="fc-hd-ic"><svg viewBox="0 0 24 24" fill="none" stroke="rgba(196,153,26,.85)" stroke-width="1.8"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg></div><span class="fc-hd-t">Contact &amp; Localisation</span><span class="fc-hd-s">Coordonnées</span></div>
              <div class="fc-body">
                <div class="frow">
                  <div class="field"><label>Email <em>*</em></label><input type="email" name="email" id="f_em" placeholder="votre@email.com"><span class="err" id="e_em">Email invalide</span></div>
                  <div class="field"><label>Téléphone <em>*</em></label><input type="tel" name="telephone" id="f_tel" placeholder="votre numéro"><span class="err" id="e_tel">Numéro requis</span></div>
                </div>
                <div class="field"><label>Adresse</label><input type="text" name="adresse" id="f_adr" placeholder="Avenue, numéro, quartier…"></div>
                <div class="frow">
                  <div class="field" id="provinceField">
                    <label>Province <em>*</em></label>
                    <select name="province" id="f_prov">
                      <option value="">Sélectionner…</option>
                      <option>Kinshasa</option><option>Bas-Uele</option><option>Équateur</option><option>Haut-Katanga</option><option>Haut-Lomami</option><option>Haut-Uele</option><option>Ituri</option><option>Kasaï</option><option>Kasaï Central</option><option>Kasaï Oriental</option><option>Kongo Central</option><option>Kwango</option><option>Kwilu</option><option>Lomami</option><option>Lualaba</option><option>Mai-Ndombe</option><option>Maniema</option><option>Mongala</option><option>Nord-Kivu</option><option>Nord-Ubangi</option><option>Sankuru</option><option>Sud-Kivu</option><option>Sud-Ubangi</option><option>Tanganyika</option><option>Tshopo</option><option>Tshuapa</option>
                    </select>
                    <span class="err" id="e_prov">Champ requis</span>
                  </div>
                  <div class="field" id="villeField">
                    <label>Ville <em>*</em></label>
                    <select name="ville" id="f_vil">
                      <option value="">Sélectionnez d'abord une province</option>
                    </select>
                    <span class="err" id="e_vil">Champ requis</span>
                  </div>
                </div>
              </div>
            </div>

            <!-- Profil -->
            <div class="fc" id="tour-card-profil">
              <div class="fc-hd"><div class="fc-hd-ic"><svg viewBox="0 0 24 24" fill="none" stroke="rgba(196,153,26,.85)" stroke-width="1.8"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg></div><span class="fc-hd-t">Profil</span><span class="fc-hd-s">Parcours &amp; engagements</span></div>
              <div class="fc-body">
                <div class="field">
                  <label>Niveau d'éducation <em>*</em></label>
                  <select name="niveau_education" id="f_edu">
                    <option value="">Sélectionner…</option>
                    <option value="Bac">Diplôme d'État / Baccalauréat</option>
                    <option value="Licence">Licence / Bac+3</option>
                    <option value="Master">Master / Bac+5</option>
                    <option value="Doctorat">Doctorat</option>
                  </select>
                  <span class="err" id="e_edu">Sélectionnez votre niveau</span>
                </div>
                <div class="field"><label>Biographie &amp; Motivation</label><textarea name="bio" id="f_bio" placeholder="Votre parcours, vos motivations pour représenter la RDC…"></textarea></div>
                <div class="chk-list" id="tour-engagements">
                  <label class="chk-row"><input type="checkbox" name="f_cb1" id="f_cb1"><span class="chk-row-t">Je confirme être <strong>célibataire, sans enfant</strong> et ne pas être enceinte.</span></label>
                  <label class="chk-row"><input type="checkbox" name="f_cb2"  id="f_cb2"><span class="chk-row-t">Je m'engage à être <strong>disponible</strong> pour les événements et déplacements officiels.</span></label>
                  <label class="chk-row"><input type="checkbox" name="f_cb3"  id="f_cb3"><span class="chk-row-t">J'accepte le <strong>règlement officiel</strong> de <?= htmlspecialchars($siteName) ?> 2026.</span></label>
                </div>
              </div>
              <div class="fc-ft">
                <span class="ft-note"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Données protégées &amp; confidentielles</span>
                <div class="ft-actions">
                  <a href="index.php" class="btn-cancel"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>Annuler</a>
                  <button type="submit" class="btn-sub" id="subBtn">
                    <div class="spin"></div>
                    <svg id="btnIco" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="width:15px;height:15px"><path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 17l-6.2 4.3 2.4-7.4L2 9.4h7.6z"/></svg>
                    <span id="btnTxt">Soumettre ma candidature</span>
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- COLONNE DROITE -->
          <div class="col-right">
            <div class="fc">
              <div class="fc-hd"><div class="fc-hd-ic"><svg viewBox="0 0 24 24" fill="none" stroke="rgba(196,153,26,.85)" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div><span class="fc-hd-t">À savoir</span></div>
              <div class="fc-body" style="gap:0">
                <div class="tip-r"><div class="t-ic"><svg viewBox="0 0 24 24" fill="none" stroke="rgba(42,122,79,.9)" stroke-width="2.2"><polyline points="20 6 9 17 4 12"/></svg></div><div class="t-tx">Les champs <strong style="color:var(--red)">*</strong> sont tous obligatoires</div></div>
                <div class="tip-r"><div class="t-ic"><svg viewBox="0 0 24 24" fill="none" stroke="rgba(196,153,26,.75)" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></div><div class="t-tx">Portrait net, fond neutre clair</div></div>
                <div class="tip-r"><div class="t-ic"><svg viewBox="0 0 24 24" fill="none" stroke="rgba(196,153,26,.75)" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></div><div class="t-tx">Données confidentielles et sécurisées</div></div>
                <div class="tip-r"><div class="t-ic"><svg viewBox="0 0 24 24" fill="none" stroke="rgba(196,153,26,.75)" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></div><div class="t-tx">Confirmation email sous 48 h</div></div>
              </div>
            </div>
            <div class="fc">
              <div class="fc-hd"><div class="fc-hd-ic"><svg viewBox="0 0 24 24" fill="none" stroke="rgba(196,153,26,.85)" stroke-width="1.8"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg></div><span class="fc-hd-t">Besoin d'aide ?</span></div>
              <div class="fc-body" style="gap:0">
                <div class="ct-r"><div class="ct-ic"><svg viewBox="0 0 24 24" fill="none" stroke="rgba(196,153,26,.75)" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></div><span class="ct-v">royaumeblessing243@gmail.com</span></div>
                <div class="ct-r"><div class="ct-ic"><svg viewBox="0 0 24 24" fill="none" stroke="rgba(196,153,26,.75)" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg></div><span class="ct-v">+243 821835560</span></div>
                <div class="ct-r"><div class="ct-ic"><svg viewBox="0 0 24 24" fill="none" stroke="rgba(196,153,26,.75)" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div><span class="ct-v">Lun – Ven · 9h – 17h (Kinshasa)</span></div>
              </div>
            </div>
          </div>
        </div>
      </form>
    </section>

    <!-- SUCCESS -->
    <div class="insc-success" id="inscSuccess">
      <div class="s-ring"><svg viewBox="0 0 24 24" fill="none" stroke="rgba(42,122,79,.85)" stroke-width="1.8"><polyline points="20 6 9 17 4 12"/></svg></div>
      <h2>Candidature envoyée !</h2>
      <p>Merci pour votre inscription à <?= htmlspecialchars($siteName) ?> 2026.<br>Votre dossier sera examiné et vous recevrez une confirmation par email sous 48 h.</p>
      <a href="index.php" class="btn-back"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>Retour à l'accueil</a>
    </div>

</div>

<script>
(function(){
'use strict';

/* ─── SLIDESHOW ────────────────────────────────────────────── */
(function(){
  const slides  = document.querySelectorAll('.slide');
  const dots    = document.querySelectorAll('.h-dot');
  const counter = document.getElementById('hcount');
  let cur = 0, tmr;
  function go(n){
    slides[cur].classList.remove('active'); slides[cur].classList.add('exit'); dots[cur].classList.remove('on');
    const p = cur; setTimeout(()=>slides[p].classList.remove('exit'),1500);
    cur = n; slides[cur].classList.add('active'); dots[cur].classList.add('on');
    counter.innerHTML = '<strong>'+String(cur+1).padStart(2,'0')+'</strong> / 0'+slides.length;
  }
  function nx(){go((cur+1)%slides.length);}
  tmr = setInterval(nx,5000);
  dots.forEach((d,i)=>d.addEventListener('click',()=>{clearInterval(tmr);go(i);tmr=setInterval(nx,5000);}));
  document.addEventListener('keydown',e=>{
    if(e.key==='ArrowRight'){clearInterval(tmr);nx();tmr=setInterval(nx,5000);}
    if(e.key==='ArrowLeft'){clearInterval(tmr);go((cur-1+slides.length)%slides.length);tmr=setInterval(nx,5000);}
  });
  let tx=0;
  const hero=document.querySelector('.hero');
  hero.addEventListener('touchstart',e=>{tx=e.touches[0].clientX;},{passive:true});
  hero.addEventListener('touchend',e=>{
    const dx=e.changedTouches[0].clientX-tx;
    if(Math.abs(dx)>50){clearInterval(tmr);dx<0?nx():go((cur-1+slides.length)%slides.length);tmr=setInterval(nx,5000);}
  });
})();

/* ─── REVEAL ────────────────────────────────────────────────── */
const obs = new IntersectionObserver(es=>{
  es.forEach(e=>{if(e.isIntersecting){e.target.classList.add('on');obs.unobserve(e.target);}});
},{threshold:.07});
document.querySelectorAll('.rv').forEach(el=>obs.observe(el));

/* ─── PHOTO UPLOAD ─────────────────────────────────────────── */
const pI   = document.getElementById('pInput');
const pD   = document.getElementById('pDrop');
const pH   = document.getElementById('pHolder');
const pP   = document.getElementById('pPrev');
const pImg = document.getElementById('pImg');
const pRm  = document.getElementById('pRm');
const pInfo= document.getElementById('pinfo');

pI.addEventListener('change', handlePhoto);
pD.addEventListener('dragover', e=>{e.preventDefault();pD.classList.add('over');});
pD.addEventListener('dragleave',()=>pD.classList.remove('over'));
pD.addEventListener('drop',e=>{
  e.preventDefault();pD.classList.remove('over');
  if(e.dataTransfer.files.length){pI.files=e.dataTransfer.files;handlePhoto();}
});
pRm.addEventListener('click',()=>{
  pI.value='';pP.style.display='none';pH.style.display='block';pD.style.display='block';pInfo.textContent='';
});
function handlePhoto(){
  const f=pI.files[0]; if(!f) return;
  if(!['image/jpeg','image/png','image/webp','image/gif'].includes(f.type)){alert('Format non supporté.');pI.value='';return;}
  if(f.size>5*1024*1024){alert('Photo max 5 Mo.');pI.value='';return;}
  const r=new FileReader();
  r.onload=e=>{
    pImg.src=e.target.result;
    pP.style.display='block';pH.style.display='none';pD.style.display='none';
    pInfo.textContent=f.name+' — '+(f.size/1024/1024).toFixed(2)+' Mo';
  };
  r.readAsDataURL(f);
}

/* ─── VALIDATION TEMPS RÉEL ────────────────────────────────── */
const rules=[
  {id:'f_nom', fn:v=>v.trim().length>=2,          e:'e_nom'},
  {id:'f_pn',  fn:v=>v.trim().length>=2,          e:'e_pn'},
  {id:'f_pr',  fn:v=>v.trim().length>=2,          e:'e_pr'},
  {id:'f_age', fn:v=>{const n=parseInt(v);return n>=18&&n<=28;}, e:'e_age'},
  {id:'f_tal', fn:v=>parseInt(v)>=160,             e:'e_tal'},
  {id:'f_em',  fn:v=>/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v), e:'e_em'},
  {id:'f_tel', fn:v=>v.trim().length>=8,           e:'e_tel'},
  {id:'f_edu', fn:v=>v.trim().length>0,            e:'e_edu'},
  {id:'f_prof',fn:v=>v.trim().length>0,            e:'e_prof'},
];

rules.forEach(r=>{
  const el=document.getElementById(r.id); if(!el) return;
  el.addEventListener(el.tagName==='SELECT'?'change':'input',()=>{
    const f=el.closest('.field'); if(!f) return;
    const ok=r.fn(el.value);
    f.classList.toggle('has-err',!ok);
    f.classList.toggle('ok',ok);
  });
});

function validateProvince(){
  const el = document.getElementById('f_prov');
  const val = el.value.trim();
  const valid = val.length > 0;
  const fieldDiv = document.getElementById('provinceField');
  fieldDiv.classList.toggle('has-err', !valid);
  fieldDiv.classList.toggle('ok', valid);
  return valid;
}
function validateVille(){
  const el = document.getElementById('f_vil');
  const val = el.value.trim();
  const valid = val.length > 0;
  const fieldDiv = document.getElementById('villeField');
  fieldDiv.classList.toggle('has-err', !valid);
  fieldDiv.classList.toggle('ok', valid);
  return valid;
}

document.getElementById('f_prov').addEventListener('change', ()=>{ validateProvince(); updateCities(); });
document.getElementById('f_vil').addEventListener('change', validateVille);

/* ─── VILLES PAR PROVINCE ──────────────────────────────────── */
const provinceCities = {
  'Kinshasa': ['Kinshasa'],
  'Bas-Uele': ['Buta', 'Aketi', 'Bondo', 'Bambesa', 'Poko'],
  'Équateur': ['Mbandaka', 'Bikoro', 'Basankusu', 'Lukolela', 'Ingende'],
  'Haut-Katanga': ['Lubumbashi', 'Likasi', 'Kipushi', 'Kambove', 'Kasumbalesa', 'Sakania'],
  'Haut-Lomami': ['Kamina', 'Malemba-Nkulu', 'Bukama', 'Lubudi', 'Kabongo'],
  'Haut-Uele': ['Isiro', 'Wamba', 'Dungu', 'Rungu', 'Niangara'],
  'Ituri': ['Bunia', 'Mahagi', 'Aru', 'Irumu', 'Mambasa'],
  'Kasaï': ['Luebo', 'Tshikapa', 'Mweka', 'Ilebo', 'Demba'],
  'Kasaï Central': ['Kananga', 'Demba', 'Tshilenge', 'Luiza', 'Lusambo'],
  'Kasaï Oriental': ['Mbuji-Mayi', 'Minga', 'Lupatapata', 'Tshilenge', 'Katanda'],
  'Kongo Central': ['Matadi', 'Boma', 'Moanda', 'Mbanza-Ngungu', 'Kimpese', 'Tshela'],
  'Kwango': ['Kenge', 'Kasongo-Lunda', 'Popokabaka', 'Feshi', 'Kahemba'],
  'Kwilu': ['Bandundu', 'Kikwit', 'Bulungu', 'Gungu', 'Idiofa', 'Masi-Manimba'],
  'Lomami': ['Kabinda', 'Lubao', 'Mwene-Ditu', 'Kamiji', 'Ngandajika'],
  'Lualaba': ['Kolwezi', 'Dilolo', 'Lubudi', 'Mutshatsha', 'Kashobwe'],
  'Mai-Ndombe': ['Inongo', 'Nioki', 'Mushie', 'Kutu', 'Bolobo'],
  'Maniema': ['Kindu', 'Kasongo', 'Lubutu', 'Pangi', 'Punia'],
  'Mongala': ['Lisala', 'Bumba', 'Bongandanga', 'Businga', 'Yahuma'],
  'Nord-Kivu': ['Goma', 'Beni', 'Butembo', 'Rutshuru', 'Lubero', 'Masisi'],
  'Nord-Ubangi': ['Gbadolite', 'Bosobolo', 'Yakoma', 'Mobayi-Mbongo'],
  'Sankuru': ['Lusambo', 'Lodja', 'Lubefu', 'Tshumbe', 'Katako-Kombe'],
  'Sud-Kivu': ['Bukavu', 'Uvira', 'Kalehe', 'Mwenga', 'Kabare', 'Shabunda'],
  'Sud-Ubangi': ['Gemena', 'Zongo', 'Libenge', 'Kungu', 'Businga'],
  'Tanganyika': ['Kalemie', 'Kongolo', 'Kabalo', 'Manono', 'Moba', 'Nyunzu'],
  'Tshopo': ['Kisangani', 'Yangambi', 'Bafwasende', 'Isangi', 'Banalia'],
  'Tshuapa': ['Boende', 'Bokungu', 'Ikela', 'Djolu', 'Monkoto']
};

function updateCities() {
  const province = document.getElementById('f_prov').value;
  const villeSelect = document.getElementById('f_vil');
  villeSelect.innerHTML = '<option value="">Sélectionnez une ville</option>';
  if (province && provinceCities[province]) {
    provinceCities[province].forEach(city => {
      const opt = document.createElement('option');
      opt.value = city;
      opt.textContent = city;
      villeSelect.appendChild(opt);
    });
  }
  validateVille();
}
updateCities();

/* ─── PROFESSION AUTRE ─────────────────────────────────────── */
const profSelect = document.getElementById('f_prof');
const autreField = document.getElementById('autreProfField');
profSelect.addEventListener('change', function() {
  if (this.value === 'Autre') {
    autreField.style.display = 'flex';
  } else {
    autreField.style.display = 'none';
  }
});

/* ─── SOUMISSION FORMULAIRE ────────────────────────────────── */
const form   = document.getElementById('addForm');
const btn    = document.getElementById('subBtn');
const ico    = document.getElementById('btnIco');
let photoFile= null;
pI.addEventListener('change',()=>{photoFile=pI.files[0]||null;});

form.addEventListener('submit', async e=>{
  e.preventDefault();
  let ok=true;
  rules.forEach(r=>{
    const el=document.getElementById(r.id); if(!el) return;
    const f=el.closest('.field'); if(!f) return;
    let valid = r.fn(el.value);
    f.classList.toggle('has-err',!valid);
    f.classList.toggle('ok',valid);
    if(!valid) ok=false;
  });
  if (!validateProvince()) ok = false;
  if (!validateVille()) ok = false;

  const cb1=document.getElementById('f_cb1');
  const cb2=document.getElementById('f_cb2');
  const cb3=document.getElementById('f_cb3');
  if(!cb1.checked||!cb2.checked||!cb3.checked){
    showAlert('Veuillez cocher les trois engagements obligatoires.','error'); ok=false;
  }
  if(!photoFile){
    showAlert('Veuillez ajouter votre photo de profil.','error'); ok=false;
  }
  if(!ok) return;

  btn.classList.add('loading'); btn.disabled=true; ico.style.display='none';
  const fd=new FormData(form);
  fd.append('photo',photoFile);
  fd.append('submit_candidature', '1');
  try{
    const res=await fetch('',{method:'POST',body:fd});
    const contentType=res.headers.get('content-type')||'';
    if(!contentType.includes('application/json')){
      const text=await res.text();
      console.error('[PHP raw output]',text);
      throw new Error('Le serveur n\'a pas renvoyé du JSON. Voir console.');
    }
    const json=await res.json();
    btn.classList.remove('loading'); btn.disabled=false; ico.style.display='';
    if(json.success){
      document.querySelector('.sec-form').style.display='none';
      document.querySelector('.sec-crit').style.display='none';
      document.querySelector('.band').style.display='none';
      document.getElementById('inscSuccess').classList.add('show');
      window.scrollTo({top:0,behavior:'smooth'});
    } else {
      showAlert(json.message||'Une erreur est survenue.','error');
    }
  } catch(err){
    btn.classList.remove('loading'); btn.disabled=false; ico.style.display='';
    showAlert('Erreur : '+err.message,'error');
    console.error('[submit error]',err);
  }
});

function showAlert(msg,type='error'){
  const old=document.getElementById('_toast'); if(old) old.remove();
  const t=document.createElement('div');
  t.id='_toast';
  Object.assign(t.style,{
    position:'fixed',bottom:'28px',left:'50%',
    transform:'translateX(-50%) translateY(20px)',
    background:type==='error'?'#B83232':'#2A7A4F',
    color:'#fff',padding:'13px 24px',borderRadius:'9px',
    fontFamily:'Jost,sans-serif',fontSize:'.82rem',fontWeight:'500',
    boxShadow:'0 8px 32px rgba(0,0,0,.25)',zIndex:'9999',
    maxWidth:'90vw',textAlign:'center',opacity:'0',
    transition:'all .35s cubic-bezier(.22,1,.36,1)',pointerEvents:'none',
  });
  t.textContent=msg;
  document.body.appendChild(t);
  requestAnimationFrame(()=>{t.style.opacity='1';t.style.transform='translateX(-50%) translateY(0)';});
  setTimeout(()=>{
    t.style.opacity='0';t.style.transform='translateX(-50%) translateY(10px)';
    setTimeout(()=>t.remove(),400);
  },4000);
}

})();
</script>
</body>
</html>