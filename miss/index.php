
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($siteName) ?> — Miss Aurora RDC</title>
    <?php if (!empty($siteLogoUrl)): ?>
    <link rel="icon" type="image/png" href="<?= $siteLogoUrl ?>">
    <?php endif; ?>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,700;1,300;1,700&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
/* ═══════ TOKENS ═══════ */
:root { --gold: #C9A84C; --gold-light: #E8CC80; --gold-dim: rgba(201,168,76,.15); --gold-border: rgba(201,168,76,.32); --black: #080808; --white: #FFFFFF; --muted: rgba(255,255,255,.46); --overlay: linear-gradient(108deg, rgba(8,8,8,.92) 0%, rgba(8,8,8,.52) 55%, rgba(8,8,8,.06) 100%); --bg2: #0A0A0A; --gold-lt: var(--gold-light); --gold-bdr: var(--gold-border); --muted2: rgba(255,255,255,.4); }
*,* ::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Outfit', sans-serif; background: var(--black); color: #fff; -webkit-font-smoothing: antialiased; }
a { text-decoration: none; color: inherit; }
img { display: block; max-width: 100%; }

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
.ml-submenu__text { flex: 1; }
.ml-submenu__badge { font-size: .62rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; padding: 2px 7px; border-radius: 999px; }
.ml-submenu__badge--live { background: rgba(34,197,94,.15); color: #4ade80; border: 1px solid rgba(34,197,94,.3); }
.ml-submenu__badge--closed { background: rgba(239,68,68,.1); color: #f87171; border: 1px solid rgba(239,68,68,.25); }
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
</style>
</head>
<body>

<!-- ═══ HEADER ═══ -->
<header class="ml-header is-sticky" id="mlHeader">
    <a href="index.php" class="ml-logo" aria-label="<?= esc($siteName) ?> — Accueil">
        <img src="millenium.webp" width="90" height="50" alt="Logo <?= esc($siteName) ?>">
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

<!-- ═══ MOBILE MENU ═══ -->
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
    <div class="ml-mobile-menu__foot"><?= esc($siteName) ?> — <?= date('Y') ?></div>
</div>
<div class="ml-overlay" id="mlOverlay" aria-hidden="true"></div>

<!-- ═══════════════════════════════════════════════════════════
     SECTIONS (ordre d'emplacement)
     ═══════════════════════════════════════════════════════════ -->


<!-- ═══ FOOTER ═══ -->
<footer style="background:#080808;padding:80px 60px 0;font-family:'Outfit',sans-serif;position:relative;overflow:hidden;">
    <div style="position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,#C6973F 25%,#E2BD6E 50%,#C6973F 75%,transparent);"></div>
    <div style="max-width:1280px;margin:0 auto;padding:0 40px;position:relative;z-index:2;">
        <div style="display:grid;grid-template-columns:1.6fr 1fr 1fr 1.3fr;gap:56px;padding-bottom:64px;border-bottom:1px solid rgba(255,255,255,.07);">
            <div>
                <a href="index.php" style="display:flex;align-items:center;gap:11px;text-decoration:none;margin-bottom:18px;">
                    <img src="millenium.webp" width="150" height="100" style="object-fit:contain;" alt="Logo <?= esc($siteName) ?>">
                    <span style="font-family:'Cormorant Garamond',serif;font-size:1.4rem;font-weight:700;color:#fff;letter-spacing:.02em;"><?= esc($siteName) ?></span>
                </a>
                <p style="font-size:.875rem;font-weight:300;line-height:1.8;color:#fff;margin-bottom:26px;max-width:280px;">LME GROUP organise des événements et accompagne les initiatives qui font rayonner les talents, la culture, le leadership et le développement communautaire en RDC.</p>
                <div style="display:flex;gap:10px;">
                    <a href="#" style="width:38px;height:38px;border-radius:10px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.55);text-decoration:none;transition:all .25s ease;flex-shrink:0;" aria-label="Facebook"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg></a>
                    <a href="#" style="width:38px;height:38px;border-radius:10px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.55);text-decoration:none;transition:all .25s ease;flex-shrink:0;" aria-label="Instagram"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="20" height="20" x="2" y="2" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" x2="17.51" y1="6.5" y2="6.5"/></svg></a>
                    <a href="#" style="width:38px;height:38px;border-radius:10px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.55);text-decoration:none;transition:all .25s ease;flex-shrink:0;" aria-label="X"><svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg></a>
                    <a href="#" style="width:38px;height:38px;border-radius:10px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.55);text-decoration:none;transition:all .25s ease;flex-shrink:0;" aria-label="YouTube"><svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="currentColor"><path d="M22.54 6.42a2.78 2.78 0 0 0-1.95-1.96C18.88 4 12 4 12 4s-6.88 0-8.59.46A2.78 2.78 0 0 0 1.46 6.42 29 29 0 0 0 1 12a29 29 0 0 0 .46 5.58 2.78 2.78 0 0 0 1.95 1.96C5.12 20 12 20 12 20s6.88 0 8.59-.46a2.78 2.78 0 0 0 1.96-1.96A29 29 0 0 0 23 12a29 29 0 0 0-.46-5.58z"/><polygon points="9.75 15.02 15.5 12 9.75 8.98 9.75 15.02" fill="#080808"/></svg></a>
                </div>
            </div>
            <div>
                <h3 style="font-family:'Cormorant Garamond',serif;font-size:1.05rem;font-weight:700;color:#fff;letter-spacing:.04em;margin-bottom:22px;display:flex;align-items:center;gap:8px;">Navigation<span style="flex:1;height:1px;background:linear-gradient(90deg,rgba(201,168,76,.3),transparent);"></span></h3>
                <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:2px;">
                    <li><a href="#apropos" style="display:flex;align-items:center;gap:8px;font-size:.875rem;font-weight:400;color:#fff;text-decoration:none;padding:6px 0;border-bottom:1px solid transparent;transition:color .22s ease,gap .22s ease;"><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>À propos</a></li>
                    <li><a href="#concours" style="display:flex;align-items:center;gap:8px;font-size:.875rem;font-weight:400;color:#fff;text-decoration:none;padding:6px 0;border-bottom:1px solid transparent;transition:color .22s ease,gap .22s ease;"><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>Compétition</a></li>
                    <li><a href="#candidates" style="display:flex;align-items:center;gap:8px;font-size:.875rem;font-weight:400;color:#fff;text-decoration:none;padding:6px 0;border-bottom:1px solid transparent;transition:color .22s ease,gap .22s ease;"><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>Candidates</a></li>
                    <li><a href="#contact" style="display:flex;align-items:center;gap:8px;font-size:.875rem;font-weight:400;color:#fff;text-decoration:none;padding:6px 0;border-bottom:1px solid transparent;transition:color .22s ease,gap .22s ease;"><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>Contact</a></li>
                </ul>
            </div>
            <div>
                <h3 style="font-family:'Cormorant Garamond',serif;font-size:1.05rem;font-weight:700;color:#fff;letter-spacing:.04em;margin-bottom:22px;display:flex;align-items:center;gap:8px;">Contact<span style="flex:1;height:1px;background:linear-gradient(90deg,rgba(201,168,76,.3),transparent);"></span></h3>
                <div style="display:flex;flex-direction:column;gap:16px;">
                    <div style="display:flex;align-items:flex-start;gap:12px;">
                        <span style="width:32px;height:32px;border-radius:8px;background:rgba(255,255,255,.06);border:1px solid rgba(201,168,76,.18);display:flex;align-items:center;justify-content:center;color:#C6973F;flex-shrink:0;margin-top:1px;" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg></span>
                        <span style="font-size:.845rem;font-weight:300;line-height:1.6;color:#fff;">40, Avenue Kasangulu, Commune de Kasa-Vubu<br>Kinshasa, RDC</span>
                    </div>
                    <div style="display:flex;align-items:flex-start;gap:12px;">
                        <span style="width:32px;height:32px;border-radius:8px;background:rgba(255,255,255,.06);border:1px solid rgba(201,168,76,.18);display:flex;align-items:center;justify-content:center;color:#C6973F;flex-shrink:0;margin-top:1px;" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.27h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.9a16 16 0 0 0 6.09 6.09l.91-.91a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg></span>
                        <span style="font-size:.845rem;font-weight:300;line-height:1.6;color:#fff;"><a href="https://wa.me/243860370727" target="_blank" style="color:#fff;text-decoration:none;transition:color .22s;"><?= esc($contactPhone) ?></a><br><a href="tel:<?= str_replace(['+', ' '], '', $contactPhone) ?>" style="color:#fff;text-decoration:none;transition:color .22s;"><?= esc($contactPhone) ?></a></span>
                    </div>
                    <div style="display:flex;align-items:flex-start;gap:12px;">
                        <span style="width:32px;height:32px;border-radius:8px;background:rgba(255,255,255,.06);border:1px solid rgba(201,168,76,.18);display:flex;align-items:center;justify-content:center;color:#C6973F;flex-shrink:0;margin-top:1px;" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg></span>
                        <span style="font-size:.845rem;font-weight:300;line-height:1.6;color:#fff;"><a href="mailto:<?= esc($contactEmail) ?>" style="color:#fff;text-decoration:none;transition:color .22s;"><?= esc($contactEmail) ?></a></span>
                    </div>
                    <div style="display:flex;align-items:flex-start;gap:12px;">
                        <span style="width:32px;height:32px;border-radius:8px;background:rgba(255,255,255,.06);border:1px solid rgba(201,168,76,.18);display:flex;align-items:center;justify-content:center;color:#C6973F;flex-shrink:0;margin-top:1px;" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20"/></svg></span>
                        <span style="font-size:.845rem;font-weight:300;line-height:1.6;color:#fff;">Site web : <em style="color:rgba(255,255,255,.6);font-style:italic;">en cours de création</em></span>
                    </div>
                    <div style="display:flex;align-items:flex-start;gap:12px;">
                        <span style="width:32px;height:32px;border-radius:8px;background:rgba(255,255,255,.06);border:1px solid rgba(201,168,76,.18);display:flex;align-items:center;justify-content:center;color:#C6973F;flex-shrink:0;margin-top:1px;" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12h6"/><path d="M9 16h6"/><rect x="4" y="3" width="16" height="18" rx="2"/></svg></span>
                        <span style="font-size:.845rem;font-weight:300;line-height:1.6;color:#fff;">RCCM / ID NAT : <em style="color:rgba(255,255,255,.6);font-style:italic;">en cours de procédure</em></span>
                    </div>
                </div>
            </div>
            <div>
                <h3 style="font-family:'Cormorant Garamond',serif;font-size:1.05rem;font-weight:700;color:#fff;letter-spacing:.04em;margin-bottom:22px;display:flex;align-items:center;gap:8px;">Newsletter<span style="flex:1;height:1px;background:linear-gradient(90deg,rgba(201,168,76,.3),transparent);"></span></h3>
                <p style="font-size:.875rem;font-weight:300;line-height:1.7;color:#fff;margin-bottom:18px;">Recevez les actualités, opportunités et projets portés par <?= esc($siteName) ?>.</p>
                <form style="display:flex;flex-direction:column;gap:10px;" onsubmit="event.preventDefault();this.querySelector('.ft__nl-btn').innerHTML='✅ Inscrit !';">
                    <div style="position:relative;"><input type="email" placeholder="Votre adresse email" required autocomplete="email" aria-label="Adresse email newsletter" style="width:100%;padding:12px 16px;border-radius:10px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);color:#fff;font-family:'Outfit',sans-serif;font-size:.875rem;font-weight:300;outline:none;transition:border-color .25s,background .25s;"></div>
                    <button type="submit" style="display:flex;align-items:center;justify-content:center;gap:8px;padding:12px 20px;border-radius:10px;background:#C6973F;color:#fff;font-family:'Outfit',sans-serif;font-size:.875rem;font-weight:700;letter-spacing:.04em;border:none;cursor:pointer;transition:background .25s,transform .25s,box-shadow .25s;box-shadow:0 4px 16px rgba(201,168,76,.3);">S'inscrire <svg style="transition:transform .25s;" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></button>
                    <p style="font-size:.7rem;color:rgba(255,255,255,.4);line-height:1.5;letter-spacing:.02em;">Aucun spam. Désinscription libre à tout moment.</p>
                </form>
            </div>
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:22px 0;">
            <p style="font-size:.78rem;font-weight:300;color:rgba(255,255,255,.6);letter-spacing:.04em;">© <?= date('Y') ?> <strong><?= esc($siteName) ?></strong>. Tous droits réservés. <strong>Inspirer · Former · Transformer</strong></p>
            <nav aria-label="Liens légaux"><a href="#" style="font-size:.78rem;color:rgba(255,255,255,.6);text-decoration:none;transition:color .22s;">Made by Zaloria Tech</a></nav>
        </div>
    </div>
</footer>

<!-- ═══ JS PRINCIPAL ═══ -->
<script>
(function() {
    const header = document.getElementById('mlHeader');
    const toggle = document.getElementById('mlToggle');
    const drawer = document.getElementById('mlDrawer');
    const overlay = document.getElementById('mlOverlay');
    const THRESHOLD = 40;
    function onScroll() { header.classList.toggle('is-sticky', window.scrollY > THRESHOLD); }
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
    function openDrawer() {
        toggle.classList.add('is-open'); drawer.classList.add('is-open'); overlay.classList.add('is-open');
        toggle.setAttribute('aria-expanded', 'true'); drawer.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }
    function closeDrawer() {
        toggle.classList.remove('is-open'); drawer.classList.remove('is-open'); overlay.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false'); drawer.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        document.dispatchEvent(new Event('drawerClosed'));
    }
    toggle.addEventListener('click', () => drawer.classList.contains('is-open') ? closeDrawer() : openDrawer());
    overlay.addEventListener('click', closeDrawer);
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && drawer.classList.contains('is-open')) closeDrawer(); });
})();

document.querySelectorAll('.ml-mobile-menu__toggle').forEach(toggle => {
    toggle.addEventListener('click', function() {
        const targetId = this.dataset.target;
        const submenu = document.getElementById(targetId);
        if (submenu) { submenu.classList.toggle('open'); this.querySelector('.ml-mobile-menu__arrow').classList.toggle('open'); }
    });
});
document.addEventListener('drawerClosed', function() {
    document.querySelectorAll('.ml-mobile-menu__sub.open').forEach(sub => sub.classList.remove('open'));
    document.querySelectorAll('.ml-mobile-menu__arrow.open').forEach(arrow => arrow.classList.remove('open'));
});

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
