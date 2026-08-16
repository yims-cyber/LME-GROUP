<?php
function esc($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
$siteName = 'LME GROUP';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Vote — <?= esc($siteName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,700;1,300;1,700&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ═══════ VOTE CTA ═══════ */
.vv { position: relative; background: #FAFAF8; padding: 130px 60px; overflow: hidden; text-align: center; }
.vv::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, transparent 0%, #C6973F 25%, #E2BD6E 50%, #C6973F 75%, transparent 100%); }
.vv__pattern { position: absolute; inset: 0; z-index: 0; background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' stroke='%23C6973F' stroke-opacity='0.045' stroke-width='0.6'%3E%3Cpath d='M30 0 L60 30 L30 60 L0 30 Z'/%3E%3C/g%3E%3C/svg%3E"); background-size: 60px 60px; pointer-events: none; }
.vv__pattern::after { content: ''; position: absolute; inset: 0; background: radial-gradient(ellipse 75% 80% at 50% 50%, #FAFAF8 25%, transparent 100%); }
.vv__bg-photo { position: absolute; inset: 0; z-index: 0; background: url('https://images.unsplash.com/photo-1529626455594-4ff0802cfb7e?w=1600&fit=crop') center / cover no-repeat; opacity: .04; pointer-events: none; }
.vv__wrap { position: relative; z-index: 2; max-width: 780px; margin: 0 auto; opacity: 0; transform: translateY(36px); transition: opacity .85s ease, transform .85s ease; }
.vv__wrap.is-visible { opacity: 1; transform: translateY(0); }
.vv__crown { display: flex; align-items: center; justify-content: center; margin-bottom: 28px; }
.vv__crown-ring { position: relative; width: 90px; height: 90px; border-radius: 50%; background: linear-gradient(135deg, #FBF5E6, #F0E4C0); border: 1.5px solid rgba(201,168,76,.3); display: flex; align-items: center; justify-content: center; box-shadow: 0 8px 32px rgba(201,168,76,.18); animation: vv-float 3.5s ease-in-out infinite; }
.vv__crown-ring::before { content: ''; position: absolute; inset: -8px; border-radius: 50%; border: 1px solid rgba(201,168,76,.18); animation: vv-ring-pulse 3.5s ease-in-out infinite; }
.vv__crown-ring::after { content: ''; position: absolute; inset: -16px; border-radius: 50%; border: 1px solid rgba(201,168,76,.08); animation: vv-ring-pulse 3.5s ease-in-out infinite .3s; }
@keyframes vv-float { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-10px); } }
@keyframes vv-ring-pulse { 0%,100% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.08); opacity: .6; } }
.vv__live-badge { display: inline-flex; align-items: center; gap: 9px; font-family: 'Outfit', sans-serif; font-size: 10px; font-weight: 700; letter-spacing: .22em; text-transform: uppercase; color: #1F7A52; background: rgba(16,185,129,.08); border: 1px solid rgba(16,185,129,.22); padding: 7px 18px; border-radius: 999px; margin-bottom: 28px; }
.vv__live-ping { position: relative; width: 10px; height: 10px; flex-shrink: 0; }
.vv__live-core { position: absolute; inset: 0; border-radius: 50%; background: #10b981; }
.vv__live-ring { position: absolute; inset: -3px; border-radius: 50%; border: 1.5px solid #34d399; opacity: 0; animation: vv-ping 2s ease-out infinite; }
@keyframes vv-ping { 0% { transform: scale(.6); opacity: .8; } 70% { transform: scale(1.9); opacity: 0; } 100% { opacity: 0; } }
.vv__title { font-family: 'Cormorant Garamond', serif; font-size: clamp(2.6rem, 5vw, 4.8rem); font-weight: 300; line-height: .98; color: #0C0C0C; letter-spacing: -.02em; margin-bottom: 14px; }
.vv__title em { font-style: italic; font-weight: 700; color: #C6973F; }
.vv__bar { width: 44px; height: 2px; background: linear-gradient(90deg, #C6973F, #E2BD6E); margin: 18px auto 24px; border-radius: 2px; }
.vv__text { font-family: 'Outfit', sans-serif; font-size: 1.05rem; font-weight: 300; color: #777; line-height: 1.8; margin-bottom: 10px; max-width: 600px; margin-left: auto; margin-right: auto; }
.vv__text strong { font-weight: 600; color: #1F7A52; }
.vv__stats { display: flex; justify-content: center; align-items: center; gap: 0; margin: 36px 0 44px; border: 1px solid #EDE9E0; border-radius: 16px; background: #FFFFFF; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,.04); }
.vv__stat { flex: 1; padding: 20px 24px; text-align: center; position: relative; }
.vv__stat + .vv__stat::before { content: ''; position: absolute; left: 0; top: 20%; bottom: 20%; width: 1px; background: #EDE9E0; }
.vv__stat-num { display: block; font-family: 'Cormorant Garamond', serif; font-size: 2.2rem; font-weight: 700; color: #C6973F; line-height: 1.1; margin-bottom: 4px; }
.vv__stat-label { font-family: 'Outfit', sans-serif; font-size: .7rem; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; color: #AAA; }
.vv__btns { display: flex; justify-content: center; align-items: center; gap: 14px; flex-wrap: wrap; }
.vv__btn { display: inline-flex; align-items: center; gap: 10px; font-family: 'Outfit', sans-serif; font-size: .9rem; font-weight: 700; letter-spacing: .04em; padding: 15px 36px; border-radius: 12px; text-decoration: none; border: none; cursor: pointer; transition: all .28s ease; position: relative; }
.vv__btn--primary { background: #C6973F; color: #FFFFFF; box-shadow: 0 6px 24px rgba(201,168,76,.32); }
.vv__btn--primary:hover { background: #B5882A; transform: translateY(-3px); box-shadow: 0 14px 40px rgba(201,168,76,.48); }
.vv__btn-live { position: absolute; top: -5px; right: -5px; width: 12px; height: 12px; border-radius: 50%; background: #10b981; border: 2px solid #FAFAF8; animation: vv-blink 2s infinite; }
@keyframes vv-blink { 0%,100%{opacity:1} 50%{opacity:.3} }
.vv__btn--secondary { background: transparent; color: #555; border: 1.5px solid #D8D2C8; }
.vv__btn--secondary:hover { border-color: #C6973F; color: #C6973F; background: rgba(201,168,76,.05); transform: translateY(-2px); }
.vv__btn-arrow { transition: transform .25s; }
.vv__btn--primary:hover .vv__btn-arrow, .vv__btn--secondary:hover .vv__btn-arrow { transform: translateX(4px); }
.vv__note { margin-top: 22px; font-family: 'Outfit', sans-serif; font-size: .75rem; color: #C0BAB0; letter-spacing: .04em; }
@media (max-width: 768px) { .vv { padding: 90px 28px; } .vv__stats { flex-direction: column; } .vv__stat + .vv__stat::before { top: 0; bottom: auto; left: 20%; right: 20%; width: auto; height: 1px; } .vv__btns { flex-direction: column; align-items: stretch; } .vv__btn { justify-content: center; } }
@media (max-width: 480px) { .vv { padding: 72px 20px; } }
@media (min-width: 1400px) { .vv { padding: 130px 100px; } }
@media (min-width: 1700px) { .vv { padding: 130px 160px; } }
body { font-family: 'Outfit', sans-serif; background: var(--black); color: #fff; margin: 0; }
:root { --black: #080808; }
</style>
</head>
<body>
<section class="vv" aria-labelledby="vv-title">
    <div class="vv__pattern" aria-hidden="true"></div>
    <div class="vv__bg-photo" aria-hidden="true"></div>
    <div class="vv__wrap" id="vvWrap">
        <div class="vv__crown" aria-hidden="true"><div class="vv__crown-ring"><svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="#C6973F" stroke-width="1.8"><path d="M11.562 3.266a.5.5 0 0 1 .876 0L15.39 8.87a1 1 0 0 0 1.516.294L21.183 5.5a.5.5 0 0 1 .798.519l-2.834 10.246a1 1 0 0 1-.956.734H5.81a1 1 0 0 1-.957-.734L2.02 6.02a.5.5 0 0 1 .798-.519l4.276 3.664a1 1 0 0 0 1.516-.294z"/><path d="M5 21h14"/></svg></div></div>
        <div class="vv__live-badge"><span class="vv__live-ping" aria-hidden="true"><span class="vv__live-core"></span><span class="vv__live-ring"></span></span>Vote du public — ouvert</div>
        <h2 class="vv__title" id="vv-title">Votre Vote <em>Compte !</em></h2>
        <div class="vv__bar"></div>
        <p class="vv__text">Le vote du public est <strong>actuellement ouvert</strong>. Participez à l'élection de la prochaine <?= esc($siteName) ?> — ambassadrice des causes sociales, culturelles et humanitaires de la ville.</p>
        <div class="vv__stats" role="list" aria-label="Chiffres du vote">
            <div class="vv__stat" role="listitem"><span class="vv__stat-num"><?= date('Y') ?></span><span class="vv__stat-label">Édition</span></div>
            <div class="vv__stat" role="listitem"><span class="vv__stat-num">Kinshasa</span><span class="vv__stat-label">Ville</span></div>
            <div class="vv__stat" role="listitem"><span class="vv__stat-num">Multiple Votes</span><span class="vv__stat-label">Par personne</span></div>
            <div class="vv__stat" role="listitem"><span class="vv__stat-num">Gala</span><span class="vv__stat-label">Résultats à la finale</span></div>
        </div>
        <div class="vv__btns">
            <a href="vote.php" class="vv__btn vv__btn--primary"><span class="vv__btn-live" aria-hidden="true"></span><svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m9 12 2 2 4-4"/><path d="M5 7c0-1.1.9-2 2-2h10a2 2 0 0 1 2 2v12H5V7Z"/><path d="M22 19H2"/></svg>Voter maintenant<svg class="vv__btn-arrow" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></a>
            <a href="#candidates" class="vv__btn vv__btn--secondary">Voir les candidates<svg class="vv__btn-arrow" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></a>
        </div>
    </div>
</section>
<script>
const vvWrap = document.getElementById('vvWrap');
const io = new IntersectionObserver(entries => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.classList.add('is-visible');
            io.unobserve(entry.target);
        }
    });
}, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });
if (vvWrap) io.observe(vvWrap);
</script>
</body>
</html>
