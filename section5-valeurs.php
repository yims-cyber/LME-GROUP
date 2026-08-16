<?php
function esc($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Valeurs — Miss Aurora RDC</title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,700;1,300;1,700&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ═══════ VALEURS ═══════ */
.vl { position: relative; background-image: url('texture.jpg'); padding: 100px 60px; overflow: hidden; }
.vl__grid-bg { position: absolute; inset: 0; background-image: linear-gradient(rgba(201,168,76,.03) 1px, transparent 1px), linear-gradient(90deg, rgba(201,168,76,.03) 1px, transparent 1px); background-size: 60px 60px; pointer-events: none; }
.vl__grid-bg::after { content: ''; position: absolute; inset: 0; background: radial-gradient(ellipse 100% 60% at 40% 40%, transparent 0%, #0D0D0D 100%); }
.vl__inner { position: relative; z-index: 2; max-width: 1200px; margin: 0 auto; text-align: center; }
.vl__eyebrow { display: inline-flex; align-items: center; gap: 9px; font-family: 'Outfit', sans-serif; font-size: 10.5px; font-weight: 700; letter-spacing: .22em; text-transform: uppercase; color: #C6973F; background: rgba(201,168,76,.08); border: 1px solid rgba(201,168,76,.2); padding: 7px 18px; border-radius: 999px; margin-bottom: 24px; }
.vl__title { font-family: 'Cormorant Garamond', serif; font-size: clamp(2.2rem, 3.8vw, 3.4rem); font-weight: 700; line-height: 1.1; color: #FFFFFF; letter-spacing: -.01em; margin-bottom: 14px; }
.vl__title em { font-style: italic; color: #C6973F; }
.vl__subtitle { font-family: 'Outfit', sans-serif; font-size: .95rem; font-weight: 300; color: rgba(255,255,255,.42); max-width: 480px; margin: 0 auto 64px; line-height: 1.7; }
.vl__cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
.vl__card { position: relative; background: rgba(17,17,17,0.42); border: 1px solid rgba(255,255,255,.06); border-radius: 18px; padding: 36px 28px 32px; text-align: left; cursor: default; overflow: hidden; opacity: 0; transform: translateY(36px); transition: border-color .3s ease, transform .3s ease, box-shadow .3s ease; }
.vl__card.is-visible { opacity: 1; transform: translateY(0); transition: opacity .65s ease, transform .65s ease, border-color .3s ease, box-shadow .3s ease; }
.vl__card:hover { transform: translateY(-6px); border-color: var(--card-accent, rgba(201,168,76,.3)); box-shadow: 0 16px 48px rgba(0,0,0,.35), 0 0 0 1px var(--card-accent, rgba(201,168,76,.15)); }
.vl__card::before { content: ''; position: absolute; top: -60px; right: -60px; width: 160px; height: 160px; border-radius: 50%; background: var(--card-glow, rgba(201,168,76,.06)); pointer-events: none; }
.vl__card--gold { --card-accent: rgba(201,168,76,.35); --card-glow: rgba(201,168,76,.08); }
.vl__card--crimson { --card-accent: rgba(123,26,47,.5); --card-glow: rgba(123,26,47,.10); }
.vl__card--blue { --card-accent: rgba(59,130,246,.35); --card-glow: rgba(59,130,246,.08); }
.vl__card--green { --card-accent: rgba(16,185,129,.35); --card-glow: rgba(16,185,129,.08); }
.vl__num { position: absolute; top: 22px; right: 24px; font-family: 'Cormorant Garamond', serif; font-size: 3rem; font-weight: 700; color: rgba(255,255,255,.04); line-height: 1; user-select: none; pointer-events: none; }
.vl__icon { width: 52px; height: 52px; border-radius: 13px; display: flex; align-items: center; justify-content: center; color: #fff; margin-bottom: 22px; transition: transform .3s ease; }
.vl__card:hover .vl__icon { transform: scale(1.1) rotate(-4deg); }
.vl__icon--gold { background: linear-gradient(135deg,#E2BD6E,#B5882A); box-shadow: 0 6px 18px rgba(201,168,76,.35); }
.vl__icon--crimson { background: linear-gradient(135deg,#D9466A,#7B1A2F); box-shadow: 0 6px 18px rgba(123,26,47,.35); }
.vl__icon--blue { background: linear-gradient(135deg,#60A5FA,#2563EB); box-shadow: 0 6px 18px rgba(37,99,235,.35); }
.vl__icon--green { background: linear-gradient(135deg,#34D399,#059669); box-shadow: 0 6px 18px rgba(5,150,105,.35); }
.vl__icon--violet { background: linear-gradient(135deg,#A78BFA,#6D28D9); box-shadow: 0 6px 18px rgba(109,40,217,.35); }
.vl__icon--teal { background: linear-gradient(135deg,#2DD4BF,#0F766E); box-shadow: 0 6px 18px rgba(15,118,110,.35); }
.vl__card-title { font-family: 'Cormorant Garamond', serif; font-size: 1.45rem; font-weight: 700; color: #FFFFFF; margin-bottom: 10px; }
.vl__card-text { font-family: 'Outfit', sans-serif; font-size: .875rem; font-weight: 300; color: rgba(255,255,255,.45); line-height: 1.75; }
.vl__card-line { position: absolute; bottom: 0; left: 0; right: 0; height: 2px; background: var(--card-accent, rgba(201,168,76,.3)); transform: scaleX(0); transform-origin: left; transition: transform .35s ease; border-radius: 0 0 18px 18px; }
.vl__card:hover .vl__card-line { transform: scaleX(1); }
@media (max-width: 1100px) { .vl { padding: 80px 28px; } .vl__cards { grid-template-columns: repeat(2,1fr); gap: 16px; } }
@media (max-width: 600px) { .vl { padding: 64px 20px; } .vl__cards { grid-template-columns: 1fr; } }
@media (min-width: 1400px) { .vl { padding: 100px 100px; } }
@media (min-width: 1700px) { .vl { padding: 100px 160px; } }
body { font-family: 'Outfit', sans-serif; background: var(--black); color: #fff; margin: 0; }
:root { --black: #080808; --gold: #C6973F; }
</style>
</head>
<body>
<section class="vl" aria-labelledby="vl-title">
    <div class="vl__grid-bg" aria-hidden="true"></div>
    <div class="vl__inner">
        <div class="vl__eyebrow"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11.017 2.814a1 1 0 0 1 1.966 0l1.051 5.558a2 2 0 0 0 1.594 1.594l5.558 1.051a1 1 0 0 1 0 1.966l-5.558 1.051a2 2 0 0 0-1.594 1.594l-1.051 5.558a1 1 0 0 1-1.966 0l-1.051-5.558a2 2 0 0 0-1.594-1.594l-5.558-1.051a1 1 0 0 1 0-1.966l5.558-1.051a2 2 0 0 0 1.594-1.594z"/></svg> Ce qui nous définit</div>
        <h2 class="vl__title" id="vl-title">Les valeurs qui <em>nous rassemblent</em></h2>
        <p class="vl__subtitle">Des principes qui guident chacune de nos actions, de nos collaborations et de nos projets</p>
        <div class="vl__cards">
            <div class="vl__card vl__card--gold">
                <span class="vl__num" aria-hidden="true">01</span>
                <div class="vl__icon vl__icon--gold" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11.562 3.266a.5.5 0 0 1 .876 0L15.39 8.87a1 1 0 0 0 1.516.294L21.183 5.5a.5.5 0 0 1 .798.519l-2.834 10.246a1 1 0 0 1-.956.734H5.81a1 1 0 0 1-.957-.734L2.02 6.02a.5.5 0 0 1 .798-.519l4.276 3.664a1 1 0 0 0 1.516-.294z"/><path d="M5 21h14"/></svg></div>
                <h3 class="vl__card-title">Excellence</h3>
                <p class="vl__card-text">Nous plaçons la qualité, la rigueur et le dépassement de soi au cœur de chaque projet.</p>
                <span class="vl__card-line"></span>
            </div>
            <div class="vl__card vl__card--crimson">
                <span class="vl__num" aria-hidden="true">02</span>
                <div class="vl__icon vl__icon--crimson" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 9.5a5.5 5.5 0 0 1 9.591-3.676.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5"/></svg></div>
                <h3 class="vl__card-title">Solidarité</h3>
                <p class="vl__card-text">Créer et soutenir des projets utiles aux communautés, avec une attention particulière à l'autonomisation et à l'inclusion.</p>
                <span class="vl__card-line"></span>
            </div>
            <div class="vl__card vl__card--gold">
                <span class="vl__num" aria-hidden="true">03</span>
                <div class="vl__icon vl__icon--violet" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 12 2 2 4-4"/><path d="M5 7c0-1.1.9-2 2-2h10a2 2 0 0 1 2 2v12H5V7Z"/><path d="M22 19H2"/></svg></div>
                <h3 class="vl__card-title">Intégrité</h3>
                <p class="vl__card-text">Agir avec honnêteté, transparence et cohérence dans chacun de nos engagements.</p>
                <span class="vl__card-line"></span>
            </div>
            <div class="vl__card vl__card--blue">
                <span class="vl__num" aria-hidden="true">04</span>
                <div class="vl__icon vl__icon--teal" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg></div>
                <h3 class="vl__card-title">Respect</h3>
                <p class="vl__card-text">Considérer chaque personne, chaque culture et chaque idée avec dignité et bienveillance.</p>
                <span class="vl__card-line"></span>
            </div>
            <div class="vl__card vl__card--blue">
                <span class="vl__num" aria-hidden="true">05</span>
                <div class="vl__icon vl__icon--blue" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/></svg></div>
                <h3 class="vl__card-title">Leadership</h3>
                <p class="vl__card-text">Faire grandir des leaders responsables, capables de porter une vision et de transformer leur environnement.</p>
                <span class="vl__card-line"></span>
            </div>
            <div class="vl__card vl__card--green">
                <span class="vl__num" aria-hidden="true">06</span>
                <div class="vl__icon vl__icon--green" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg></div>
                <h3 class="vl__card-title">Innovation &amp; culture</h3>
                <p class="vl__card-text">Valoriser l'identité congolaise tout en imaginant de nouvelles solutions pour répondre aux enjeux de demain.</p>
                <span class="vl__card-line"></span>
            </div>
        </div>
    </div>
</section>
<script>
const reveals = document.querySelectorAll('.vl__card');
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
