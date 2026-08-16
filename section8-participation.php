<?php
function esc($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
$siteName = 'LME GROUP';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Participation — <?= esc($siteName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,700;1,300;1,700&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ═══════ PARTICIPATION ═══════ */
.pt { position: relative; background-image: url('texture.jpg'); padding: 110px 60px 120px; overflow: hidden; }
.pt::before { content: ''; position: absolute; top: -160px; left: 50%; transform: translateX(-50%); width: 800px; height: 500px; border-radius: 50%; background: radial-gradient(ellipse, rgba(201,168,76,.08) 0%, transparent 65%); pointer-events: none; }
.pt__tex { position: absolute; inset: 0; z-index: 0; background-image: linear-gradient(rgba(201,168,76,.03) 1px, transparent 1px), linear-gradient(90deg, rgba(201,168,76,.03) 1px, transparent 1px); background-size: 64px 64px; }
.pt__tex::after { content: ''; position: absolute; inset: 0; background: radial-gradient(ellipse 100% 60% at 40% 40%, transparent 0%, #0D0D0D 100%); }
.pt__wrap { position: relative; z-index: 2; max-width: 1100px; margin: 0 auto; }
.pt__head { text-align: center; margin-bottom: 72px; }
.pt__eyebrow { display: inline-flex; align-items: center; gap: 12px; font-family: 'Outfit', sans-serif; font-size: 10px; font-weight: 700; letter-spacing: .28em; text-transform: uppercase; color: #C6973F; margin-bottom: 22px; }
.pt__eyebrow-line { width: 36px; height: 1px; background: #C6973F; }
.pt__title { font-family: 'Cormorant Garamond', serif; font-size: clamp(2.5rem, 4.5vw, 4.2rem); font-weight: 300; line-height: 1; color: #FFFFFF; letter-spacing: -.02em; margin-bottom: 12px; }
.pt__title em { font-style: italic; font-weight: 700; color: #C6973F; }
.pt__bar { width: 44px; height: 2px; background: linear-gradient(90deg, #C6973F, #E2BD6E); margin: 18px auto 22px; border-radius: 2px; }
.pt__subtitle { font-family: 'Outfit', sans-serif; font-size: .95rem; font-weight: 300; color: rgba(255,255,255,.42); max-width: 520px; margin: 0 auto; line-height: 1.75; }
.pt__grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items: start; }
.pt__card { position: relative; background: rgba(20,20,20,0.48); border: 1px solid rgba(255,255,255,.07); border-radius: 24px; overflow: hidden; opacity: 0; transform: translateY(36px); transition: opacity .75s ease, transform .75s ease, border-color .35s ease, box-shadow .35s ease; }
.pt__card.is-visible { opacity: 1; transform: translateY(0); }
.pt__card:nth-child(2) { transition-delay: .12s; }
.pt__card:hover { border-color: var(--c-border); box-shadow: 0 24px 64px rgba(0,0,0,.45), 0 0 0 1px var(--c-border); transform: translateY(-6px); }
.pt__card--gold { --c-border: rgba(201,168,76,.4); --c-accent: #C6973F; --c-glow: rgba(201,168,76,.06); }
.pt__card--crimson { --c-border: rgba(123,26,47,.55); --c-accent: #A0243C; --c-glow: rgba(123,26,47,.08); }
.pt__card::before { content: ''; position: absolute; top: -80px; right: -80px; width: 220px; height: 220px; border-radius: 50%; background: radial-gradient(circle, var(--c-glow) 0%, transparent 70%); pointer-events: none; z-index: 0; transition: opacity .35s; }
.pt__card:hover::before { opacity: 1.8; }
.pt__topbar { height: 3px; background: linear-gradient(90deg, var(--c-accent), transparent); }
.pt__inner { position: relative; z-index: 1; padding: 36px 36px 32px; }
.pt__icon { width: 58px; height: 58px; border-radius: 16px; display: flex; align-items: center; justify-content: center; color: #fff; margin-bottom: 24px; transition: transform .35s ease; flex-shrink: 0; }
.pt__card:hover .pt__icon { transform: scale(1.1) rotate(-5deg); }
.pt__icon--gold { background: linear-gradient(135deg,#E2BD6E,#B5882A); box-shadow: 0 6px 20px rgba(201,168,76,.4); }
.pt__icon--crimson { background: linear-gradient(135deg,#D9466A,#7B1A2F); box-shadow: 0 6px 20px rgba(123,26,47,.4); }
.pt__card-title { font-family: 'Cormorant Garamond', serif; font-size: 1.9rem; font-weight: 700; color: #fff; letter-spacing: -.01em; margin-bottom: 10px; line-height: 1.1; }
.pt__card-desc { font-family: 'Outfit', sans-serif; font-size: .9rem; font-weight: 300; color: rgba(255,255,255,.48); line-height: 1.75; margin-bottom: 28px; max-width: 380px; }
.pt__criteria { list-style: none; display: flex; flex-direction: column; gap: 10px; margin-bottom: 32px; }
.pt__criteria-item { display: flex; align-items: flex-start; gap: 12px; font-family: 'Outfit', sans-serif; font-size: .875rem; font-weight: 400; color: rgba(255,255,255,.65); line-height: 1.5; }
.pt__criteria-check { width: 20px; height: 20px; border-radius: 6px; background: rgba(255,255,255,.04); border: 1px solid var(--c-border); display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 1px; color: var(--c-accent); transition: background .25s, border-color .25s; }
.pt__card:hover .pt__criteria-check { background: rgba(255,255,255,.06); }
.pt__sep { height: 1px; background: linear-gradient(90deg, rgba(255,255,255,.08), transparent); margin-bottom: 28px; }
.pt__btn { display: inline-flex; align-items: center; gap: 10px; font-family: 'Outfit', sans-serif; font-size: .875rem; font-weight: 700; letter-spacing: .04em; padding: 13px 28px; border-radius: 10px; text-decoration: none; border: none; cursor: pointer; transition: all .25s ease; width: 100%; justify-content: center; }
.pt__btn--gold { background: #C6973F; color: #080808; box-shadow: 0 4px 18px rgba(201,168,76,.3); }
.pt__btn--gold:hover { background: #E2BD6E; transform: translateY(-2px); box-shadow: 0 8px 28px rgba(201,168,76,.45); }
.pt__btn--crimson { background: #7B1A2F; color: #fff; box-shadow: 0 4px 18px rgba(123,26,47,.3); }
.pt__btn--crimson:hover { background: #A0243C; transform: translateY(-2px); box-shadow: 0 8px 28px rgba(123,26,47,.45); }
.pt__btn-arrow { transition: transform .25s; }
.pt__btn:hover .pt__btn-arrow { transform: translateX(4px); }
.pt__deadline { display: inline-flex; align-items: center; gap: 6px; font-family: 'Outfit', sans-serif; font-size: .72rem; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: rgba(255,255,255,.35); margin-top: 14px; }
.pt__deadline-dot { width: 5px; height: 5px; border-radius: 50%; background: var(--c-accent); opacity: .7; }
@media (max-width: 960px) { .pt { padding: 80px 28px; } .pt__grid { grid-template-columns: 1fr; max-width: 520px; margin: 0 auto; } }
@media (max-width: 540px) { .pt { padding: 64px 20px; } .pt__inner { padding: 28px 24px 26px; } .pt__card-title { font-size: 1.6rem; } }
@media (min-width: 1400px) { .pt { padding: 110px 100px; } }
@media (min-width: 1700px) { .pt { padding: 110px 160px; } }
body { font-family: 'Outfit', sans-serif; background: var(--black); color: #fff; margin: 0; }
:root { --black: #080808; }
</style>
</head>
<body>
<section class="pt" id="partenariat" aria-labelledby="pt-title">
    <div class="pt__tex" aria-hidden="true"></div>
    <div class="pt__wrap">
        <div class="pt__head">
            <div class="pt__eyebrow"><span class="pt__eyebrow-line"></span> Rejoignez l'aventure <span class="pt__eyebrow-line"></span></div>
            <h2 class="pt__title" id="pt-title">Participez à <em>l'Aventure</em></h2>
            <div class="pt__bar"></div>
            <p class="pt__subtitle">Rejoignez <?= esc($siteName) ?> <?= date('Y') ?> en tant que candidate ou soutenez notre mission en devenant partenaire officiel</p>
        </div>
        <div class="pt__grid">
            <div class="pt__card pt__card--gold">
                <div class="pt__topbar"></div>
                <div class="pt__inner">
                    <div class="pt__icon pt__icon--gold" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11.562 3.266a.5.5 0 0 1 .876 0L15.39 8.87a1 1 0 0 0 1.516.294L21.183 5.5a.5.5 0 0 1 .798.519l-2.834 10.246a1 1 0 0 1-.956.734H5.81a1 1 0 0 1-.957-.734L2.02 6.02a.5.5 0 0 1 .798-.519l4.276 3.664a1 1 0 0 0 1.516-.294z"/><path d="M5 21h14"/></svg></div>
                    <h3 class="pt__card-title">Devenir Candidate</h3>
                    <p class="pt__card-desc">Vous avez entre 18 et 30 ans, résidez à Kinshasa ? Postulez dès maintenant pour <?= esc($siteName) ?> <?= date('Y') ?>.</p>
                    <ul class="pt__criteria" role="list">
                        <li class="pt__criteria-item"><span class="pt__criteria-check" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></span>18–30 ans, résidente de Kinshasa</li>
                        <li class="pt__criteria-item"><span class="pt__criteria-check" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></span>Nationalité congolaise, célibataire</li>
                        <li class="pt__criteria-item"><span class="pt__criteria-check" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></span>Diplôme d'État ou certificat équivalent</li>
                        <li class="pt__criteria-item"><span class="pt__criteria-check" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></span>Bonne moralité, disponible pour toutes les activités</li>
                    </ul>
                    <div class="pt__sep"></div>
                    <a href="candidatures.php" class="pt__btn pt__btn--gold">Postuler maintenant <svg class="pt__btn-arrow" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></a>
                    <p class="pt__deadline"><span class="pt__deadline-dot"></span> Inscriptions ouvertes — <?= esc($siteName) ?> <?= date('Y') ?></p>
                </div>
            </div>
            <div class="pt__card pt__card--crimson">
                <div class="pt__topbar"></div>
                <div class="pt__inner">
                    <div class="pt__icon pt__icon--crimson" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 9.5a5.5 5.5 0 0 1 9.591-3.676.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5"/></svg></div>
                    <h3 class="pt__card-title">Devenir Partenaire</h3>
                    <p class="pt__card-desc">Associez votre marque à l'engagement social et culturel de <?= esc($siteName) ?> <?= date('Y') ?>.</p>
                    <ul class="pt__criteria" role="list">
                        <li class="pt__criteria-item"><span class="pt__criteria-check" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></span>Visibilité et notoriété locale ciblée</li>
                        <li class="pt__criteria-item"><span class="pt__criteria-check" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></span>Présence officielle pendant tous les événements</li>
                        <li class="pt__criteria-item"><span class="pt__criteria-check" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></span>Contenus promotionnels sur tous les supports médias</li>
                        <li class="pt__criteria-item"><span class="pt__criteria-check" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></span>Image positive, crédible et statut officiel</li>
                    </ul>
                    <div class="pt__sep"></div>
                    <a href="#contact" class="pt__btn pt__btn--crimson">Proposer un partenariat <svg class="pt__btn-arrow" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></a>
                    <p class="pt__deadline"><span class="pt__deadline-dot"></span> Partenariats disponibles — Contactez-nous</p>
                </div>
            </div>
        </div>
        <p class="pt__confirm-note">Sponsors &amp; partenaires officiels — <em>à confirmer prochainement.</em></p>
    </div>
</section>
<script>
const reveals = document.querySelectorAll('.pt__card');
const io = new IntersectionObserver(entries => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.classList.add('is-visible');
            io.unobserve(entry.target);
        }
    });
}, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });
reveals.forEach(r => io.observe(r));
</script>
</body>
</html>
