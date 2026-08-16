<?php
function esc($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
$siteName = 'LME GROUP';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= esc($siteName) ?> — À propos</title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,700;1,300;1,700&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ═══════ ORGANISATION ═══════ */
.or { position: relative; background: #FFFFFF; padding: 100px 60px; overflow: hidden; }
.or::before { content: ''; position: absolute; top: -120px; right: -120px; width: 520px; height: 520px; border-radius: 50%; background: radial-gradient(circle, rgba(201,168,76,.10) 0%, transparent 70%); pointer-events: none; }
.or::after { content: ''; position: absolute; bottom: -100px; left: -80px; width: 400px; height: 400px; border-radius: 50%; background: radial-gradient(circle, rgba(123,26,47,.07) 0%, transparent 70%); pointer-events: none; }
.or__grid { position: relative; z-index: 2; max-width: 1200px; margin: 0 auto; display: grid; grid-template-columns: 1fr 1fr; gap: 80px; align-items: center; }
.or__left { display: flex; flex-direction: column; }
.or__eyebrow { display: inline-flex; align-items: center; gap: 10px; font-family: 'Outfit', sans-serif; font-size: 10.5px; font-weight: 700; letter-spacing: .22em; text-transform: uppercase; color: #C6973F; margin-bottom: 20px; }
.or__eyebrow::before { content: ''; width: 28px; height: 1.5px; background: #C6973F; border-radius: 2px; }
.or__title { font-family: 'Cormorant Garamond', serif; font-size: clamp(2.2rem, 3.5vw, 3.2rem); font-weight: 700; line-height: 1.1; color: #0C0C0C; margin-bottom: 10px; letter-spacing: -.01em; }
.or__title-gold { color: #C6973F; font-style: italic; }
.or__title-crimson { color: #7B1A2F; font-style: italic; }
.or__bar { width: 48px; height: 2.5px; background: linear-gradient(90deg, #C6973F, #E2BD6E); border-radius: 2px; margin: 22px 0 26px; }
.or__text { font-family: 'Outfit', sans-serif; font-size: .975rem; font-weight: 300; color: #525252; line-height: 1.85; margin-bottom: 14px; max-width: 480px; }
.or__stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-top: 32px; }
.or__stat { padding: 18px 14px; border-radius: 12px; background: #F9F7F3; border: 1px solid #EDE9E0; text-align: center; transition: border-color .22s, background .22s, transform .22s; cursor: default; }
.or__stat:hover { border-color: rgba(201,168,76,.35); background: #FBF6ED; transform: translateY(-3px); }
.or__stat-num { display: block; font-family: 'Cormorant Garamond', serif; font-size: 2rem; font-weight: 700; color: #0C0C0C; line-height: 1.1; margin-bottom: 4px; }
.or__stat-label { font-family: 'Outfit', sans-serif; font-size: .72rem; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; color: #8A8A8A; }
.or__link { display: inline-flex; align-items: center; gap: 9px; margin-top: 32px; font-family: 'Outfit', sans-serif; font-size: .9rem; font-weight: 600; color: #C6973F; text-decoration: none; letter-spacing: .02em; transition: gap .22s, color .22s; }
.or__link:hover { color: #E2BD6E; gap: 14px; }
.or__objectives { max-width: 1200px; margin: 56px auto 0; position: relative; z-index: 2; }
.or__objectives-label { display: block; font-family: 'Outfit', sans-serif; font-size: .7rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: #999; margin-bottom: 18px; text-align: center; }
.or__objectives-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
.or__obj-item { display: flex; align-items: center; gap: 10px; background: #FAFAF8; border: 1px solid #EDE9E0; border-radius: 12px; padding: 14px 16px; font-family: 'Outfit', sans-serif; font-size: .82rem; font-weight: 500; color: #333; }
.or__obj-icon { width: 22px; height: 22px; min-width: 22px; border-radius: 50%; background: rgba(31,122,82,.1); color: #1F7A52; display: flex; align-items: center; justify-content: center; }
@media (max-width: 900px) { .or__objectives-grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 560px) { .or__objectives-grid { grid-template-columns: 1fr; } }
.or__motto { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 28px; }
.or__motto-item { display:flex; align-items:flex-start; gap:14px; background: linear-gradient(160deg,rgba(20,92,197,.05),rgba(220,174,66,.05)); border: 1px solid rgba(8,38,77,.09); border-radius: 16px; padding: 20px 22px; }
.or__motto-icon { width:38px; height:38px; min-width:38px; border-radius:11px; background:rgba(220,174,66,.14); color:#B5882A; display:flex; align-items:center; justify-content:center; }
.or__motto-label { display: block; font-family: 'Outfit', sans-serif; font-size: .65rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: #B5882A; margin-bottom: 6px; }
.or__motto-text { font-family: 'Cormorant Garamond', serif; font-size: 1.08rem; font-weight: 600; font-style: italic; color: #08264D; line-height: 1.4; }
.or__domains { margin-top: 26px; }
.or__domains-label { display: block; font-family: 'Outfit', sans-serif; font-size: .7rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: #999; margin-bottom: 12px; }
.or__domains-tags { display: flex; flex-wrap: wrap; gap: 9px; }
.or__domain-tag { display:inline-flex; align-items:center; gap:7px; font-family: 'Outfit', sans-serif; font-size: .78rem; font-weight: 600; color: #08264D; background: #F5F3EE; border: 1px solid #EDE9E0; padding: 8px 16px 8px 10px; border-radius: 999px; }
.or__domain-tag::before { content:''; width:7px; height:7px; border-radius:50%; background:#C6973F; flex-shrink:0; }
@media (max-width: 640px) { .or__motto { grid-template-columns: 1fr; } }
.or__mosaic { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; position: relative; }
.or__mosaic::before { content: ''; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 260px; height: 260px; border-radius: 50%; border: 1px solid rgba(201,168,76,.12); pointer-events: none; z-index: 0; }
.or__col { display: flex; flex-direction: column; gap: 16px; position: relative; z-index: 1; }
.or__col--offset { margin-top: 52px; }
.or__img-wrap { position: relative; border-radius: 0px; overflow: hidden; cursor: pointer; }
.or__img-wrap::after { content: ''; position: absolute; inset: 0; border-radius: 16px; border: 2px solid rgba(201,168,76,.0); transition: border-color .3s; pointer-events: none; }
.or__img-wrap:hover::after { }
.or__img-wrap img { width: 100%; height: 100%; object-fit: contain; display: block; transition: transform .55s cubic-bezier(.25,.46,.45,.94), filter .3s; filter: brightness(.96); }
.or__img-wrap:hover img { transform: scale(1.05); filter: brightness(1.04); }
.or__col:first-child .or__img-wrap:nth-child(1) { height: 220px; }
.or__col:first-child .or__img-wrap:nth-child(2) { height: 170px; }
.or__col--offset .or__img-wrap:nth-child(1) { height: 170px; }
.or__col--offset .or__img-wrap:nth-child(2) { height: 220px; }
.or__img-tag { position: absolute; bottom: 12px; left: 12px; font-family: 'Outfit', sans-serif; font-size: .7rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: #0C0C0C; background: #C6973F; padding: 4px 10px; border-radius: 4px; z-index: 2; }
.or__left, .or__mosaic { opacity: 0; animation: or-rise .75s forwards ease-out; }
.or__left { animation-delay: .08s; }
.or__mosaic { animation-delay: .25s; }
@keyframes or-rise { from { opacity: 0; transform: translateY(28px); } to { opacity: 1; transform: translateY(0); } }
@media (max-width: 1024px) { .or { padding: 80px 28px; } .or__grid { grid-template-columns: 1fr; gap: 60px; } .or__text { max-width: 100%; } .or__col--offset { margin-top: 0; } }
@media (max-width: 640px) { .or { padding: 64px 20px; } .or__stats { grid-template-columns: repeat(3, 1fr); gap: 8px; } .or__stat { padding: 14px 8px; } .or__stat-num { font-size: 1.6rem; } }
@media (min-width: 1400px) { .or { padding: 100px 100px; } }
@media (min-width: 1700px) { .or { padding: 100px 160px; } }
@media (max-width: 480px) {.or__col:first-child .or__img-wrap:nth-child(1) , .or__col:first-child .or__img-wrap:nth-child(2), .or__col--offset .or__img-wrap:nth-child(1), .or__col--offset .or__img-wrap:nth-child(2){height:auto;}}
body { font-family: 'Outfit', sans-serif; background: #080808; color: #fff; margin: 0; }
</style>
</head>
<body>
<section class="or" id="apropos" aria-labelledby="or-title">
    <div class="or__grid">
        <div class="or__left">
            <span class="or__eyebrow">LME GROUP · Kinshasa</span>
            <h2 class="or__title" id="or-title">Inspirer. Former. <span class="or__title-gold">Transformer.</span></h2>
            <div class="or__bar"></div>
            <p class="or__text"><strong>LME GROUP</strong> est une structure congolaise spécialisée dans l'organisation d'événements, la communication, la promotion culturelle, le développement du leadership ainsi que l'accompagnement des initiatives sociales et entrepreneuriales en République Démocratique du Congo.</p>
            <p class="or__text">Nous créons des plateformes d'expression, de formation et de valorisation des talents, particulièrement pour la jeunesse et les femmes. Notre ambition : faire émerger des initiatives responsables, compétentes et engagées pour le développement durable du pays.</p>
            <div class="or__stats">
                <div class="or__stat"><span class="or__stat-num">6</span><span class="or__stat-label">Domaines d'expertise</span></div>
                <div class="or__stat"><span class="or__stat-num">RDC</span><span class="or__stat-label">Ancrage national</span></div>
                <div class="or__stat"><span class="or__stat-num">100%</span><span class="or__stat-label">Engagés pour l'impact</span></div>
            </div>
            <div class="or__motto">
                <div class="or__motto-item">
                    <div class="or__motto-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg></div>
                    <div><span class="or__motto-label">Vision</span><p class="or__motto-text" style="font-style:normal;font-size:.92rem;font-weight:400;">Contribuer à l'émergence d'une jeunesse congolaise responsable, compétente et engagée dans le développement durable du pays.</p></div>
                </div>
                <div class="or__motto-item">
                    <div class="or__motto-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 11 18-5v12L3 14v-3Z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg></div>
                    <div><span class="or__motto-label">Mission</span><p class="or__motto-text" style="font-style:normal;font-size:.92rem;font-weight:400;">Créer des opportunités de visibilité, de formation et de développement personnel à travers des projets innovants à fort impact social, culturel et économique.</p></div>
                </div>
            </div>
            <div class="or__motto" style="margin-top:14px;">
                <div class="or__motto-item">
                    <div class="or__motto-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg></div>
                    <div><span class="or__motto-label">Devise</span><p class="or__motto-text">« Inspirer, Former, Transformer »</p></div>
                </div>
                <div class="or__motto-item">
                    <div class="or__motto-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div>
                    <div><span class="or__motto-label">Slogan</span><p class="or__motto-text">« Ensemble pour un avenir d'excellence »</p></div>
                </div>
            </div>
            <div class="or__domains">
                <span class="or__domains-label">Domaines d'activités</span>
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
        </div>
        <div class="or__mosaic" aria-hidden="true">
            <div class="or__col">
                <div class="or__img-wrap or__img-wrap--brand">
                    <span class="or__brand-mark"><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M11.562 3.266a.5.5 0 0 1 .876 0L15.39 8.87a1 1 0 0 0 1.516.294L21.183 5.5a.5.5 0 0 1 .798.519l-2.834 10.246a1 1 0 0 1-.956.734H5.81a1 1 0 0 1-.957-.734L2.02 6.02a.5.5 0 0 1 .798-.519l4.276 3.664a1 1 0 0 0 1.516-.294z"/><path d="M5 21h14"/></svg></span>
                    <span class="or__brand-text">LME<br><em>GROUP</em></span>
                </div>
                <div class="or__img-wrap or__img-wrap--icon" style="background:#FBF1DC;color:#B5882A;min-height:140px;">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 2v20"/><path d="M2 5h20"/><path d="M5 5v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V5"/><path d="M9 9h6"/></svg>
                </div>
            </div>
            <div class="or__col or__col--offset">
                <div class="or__img-wrap or__img-wrap--icon" style="background:#EAF1FB;color:#145CC5;min-height:190px;">
                    <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><path d="M16 3.128a4 4 0 0 1 0 7.744"/><circle cx="9" cy="7" r="4"/></svg>
                </div>
                <div class="or__img-wrap or__img-wrap--icon" style="background:#08264d;color:#dcae42;min-height:180px;">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                </div>
            </div>
        </div>
    </div>
    <div class="or__objectives">
        <span class="or__objectives-label">Nos objectifs</span>
        <div class="or__objectives-grid">
            <div class="or__obj-item"><span class="or__obj-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M20 6 9 17l-5-5"/></svg></span>Promouvoir les talents congolais</div>
            <div class="or__obj-item"><span class="or__obj-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M20 6 9 17l-5-5"/></svg></span>Encourager le leadership des jeunes</div>
            <div class="or__obj-item"><span class="or__obj-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M20 6 9 17l-5-5"/></svg></span>Valoriser la culture congolaise</div>
            <div class="or__obj-item"><span class="or__obj-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M20 6 9 17l-5-5"/></svg></span>Renforcer l'autonomisation des femmes</div>
            <div class="or__obj-item"><span class="or__obj-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M20 6 9 17l-5-5"/></svg></span>Développer des projets à impact social</div>
            <div class="or__obj-item"><span class="or__obj-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M20 6 9 17l-5-5"/></svg></span>Offrir des plateformes de représentation nationale et internationale</div>
        </div>
    </div>
</section>
</body>
</html>
