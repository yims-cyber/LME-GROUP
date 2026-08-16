<?php
function esc($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
$siteName = 'LME GROUP';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fiche Concours — Miss Aurora RDC</title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,700;1,300;1,700&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ═══════ FICHE CONCOURS ═══════ */
.fc{position:relative;background:#FAFAF8;padding:120px 60px;overflow:hidden}
.fc__pattern{position:absolute;inset:0;z-index:0;background-image:radial-gradient(circle at 15% 20%,rgba(20,92,197,.05),transparent 40%),radial-gradient(circle at 85% 80%,rgba(220,174,66,.06),transparent 40%);pointer-events:none}
.fc__wrap{position:relative;z-index:2;max-width:1240px;margin:0 auto}
.fc__head{text-align:center;max-width:720px;margin:0 auto 64px}
.fc__eyebrow{display:inline-flex;align-items:center;gap:9px;font-family:'Outfit',sans-serif;font-size:11px;font-weight:700;letter-spacing:.2em;text-transform:uppercase;color:#145cc5;background:rgba(20,92,197,.07);border:1px solid rgba(20,92,197,.18);padding:8px 18px;border-radius:999px;margin-bottom:22px}
.fc__eyebrow-dot{width:6px;height:6px;border-radius:50%;background:#dcae42}
.fc__title{font-family:'Cormorant Garamond',serif;font-size:clamp(2.2rem,4.5vw,3.6rem);font-weight:300;line-height:1.05;color:#0C0C0C;letter-spacing:-.02em;margin-bottom:18px}
.fc__title em{font-style:italic;font-weight:700;color:#dcae42}
.fc__bar{width:44px;height:2px;background:linear-gradient(90deg,#dcae42,#145cc5);margin:0 auto 22px;border-radius:2px}
.fc__lead{font-family:'Outfit',sans-serif;font-size:1.02rem;font-weight:300;color:#666;line-height:1.8}
.fc__identity{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;margin-bottom:56px}
.fc__id-card{background:#fff;border-radius:20px;padding:26px 22px;box-shadow:0 8px 28px rgba(8,38,77,.06);border:1px solid rgba(8,38,77,.05);transition:transform .3s ease,box-shadow .3s ease}
.fc__id-card:hover{transform:translateY(-4px);box-shadow:0 16px 40px rgba(8,38,77,.1)}
.fc__id-label{display:block;font-family:'Outfit',sans-serif;font-size:.68rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#dcae42;margin-bottom:10px}
.fc__id-value{font-family:'Cormorant Garamond',serif;font-size:1.25rem;font-weight:600;color:#08264d;line-height:1.35}
.fc__vm{display:grid;grid-template-columns:1fr 1fr;gap:22px;margin-bottom:22px}
.fc__vm-card{background:#08264d;border-radius:24px;padding:38px;position:relative;overflow:hidden}
.fc__vm-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,#dcae42,#145cc5)}
.fc__vm-icon{width:52px;height:52px;border-radius:14px;background:rgba(220,174,66,.12);border:1px solid rgba(220,174,66,.3);color:#dcae42;display:flex;align-items:center;justify-content:center;margin-bottom:20px}
.fc__vm-title{font-family:'Cormorant Garamond',serif;font-size:1.5rem;font-weight:700;color:#fff;margin-bottom:12px}
.fc__vm-text{font-family:'Outfit',sans-serif;font-size:.92rem;font-weight:300;color:rgba(255,255,255,.68);line-height:1.75}
.fc__two-col{display:grid;grid-template-columns:1fr 1fr;gap:22px;margin-bottom:22px}
.fc__panel{background:#fff;border-radius:24px;padding:38px;box-shadow:0 8px 28px rgba(8,38,77,.06);border:1px solid rgba(8,38,77,.05)}
.fc__panel--wide{grid-column:1/-1}
.fc__panel-title{display:flex;align-items:center;gap:12px;font-family:'Cormorant Garamond',serif;font-size:1.4rem;font-weight:700;color:#08264d;margin-bottom:22px}
.fc__panel-num{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:9px;background:rgba(220,174,66,.12);color:#b5882a;font-family:'Outfit',sans-serif;font-size:.78rem;font-weight:700}
.fc__list{list-style:none;display:flex;flex-direction:column;gap:12px}
.fc__list li{position:relative;padding-left:22px;font-family:'Outfit',sans-serif;font-size:.92rem;color:#4a4a4a;line-height:1.6}
.fc__list li::before{content:'';position:absolute;left:0;top:9px;width:8px;height:8px;border-radius:2px;background:#dcae42;transform:rotate(45deg)}
.fc__tags{display:flex;flex-wrap:wrap;gap:10px}
.fc__tag{font-family:'Outfit',sans-serif;font-size:.82rem;font-weight:600;color:#145cc5;background:rgba(20,92,197,.07);border:1px solid rgba(20,92,197,.16);padding:9px 18px;border-radius:999px}
.fc__cond-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 24px;margin-bottom:26px}
.fc__cond-item{display:flex;align-items:center;gap:10px;font-family:'Outfit',sans-serif;font-size:.9rem;color:#4a4a4a}
.fc__cond-item svg{color:#1F7A52;flex-shrink:0}
.fc__docs{font-family:'Outfit',sans-serif;font-size:.85rem;color:#777;line-height:1.8;padding-top:20px;border-top:1px solid #EDE9E0}
.fc__docs-label{font-weight:700;color:#08264d}
.fc__steps{display:flex;flex-wrap:wrap;gap:12px}
.fc__step{display:flex;align-items:center;gap:10px;background:#FAFAF8;border:1px solid #EDE9E0;border-radius:14px;padding:12px 18px;font-family:'Outfit',sans-serif;font-size:.85rem;font-weight:600;color:#4a4a4a}
.fc__step-num{display:flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:#08264d;color:#fff;font-size:.72rem;font-weight:700;flex-shrink:0}
.fc__step--gold{background:rgba(220,174,66,.1);border-color:rgba(220,174,66,.3)}
.fc__step--gold .fc__step-num{background:#dcae42;color:#08264d}
.fc__pay-row{display:flex;flex-wrap:wrap;gap:10px}
.fc__pay{display:inline-flex;align-items:center;gap:7px;font-family:'Outfit',sans-serif;font-size:.8rem;font-weight:600;color:#08264d;background:#FAFAF8;border:1px solid #EDE9E0;padding:9px 16px;border-radius:10px}
.fc__pay--std{color:#666}
.fc__pay--vip{color:#145cc5;border-color:rgba(20,92,197,.25);background:rgba(20,92,197,.05)}
.fc__pay--vvip{color:#b5882a;border-color:rgba(220,174,66,.35);background:rgba(220,174,66,.08)}
.fc__social{display:flex;align-items:center;justify-content:center;gap:18px;margin-top:56px;padding-top:40px;border-top:1px solid #EDE9E0}
.fc__social-label{font-family:'Outfit',sans-serif;font-size:.78rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#999}
.fc__social-links{display:flex;gap:10px}
.fc__social-link{width:42px;height:42px;border-radius:50%;background:#fff;border:1px solid #EDE9E0;color:#08264d;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 14px rgba(8,38,77,.06);transition:all .25s ease}
.fc__social-link:hover{background:#08264d;color:#dcae42;transform:translateY(-3px)}
.fc__social-link{position:relative}
.fc__social-tip{position:absolute;bottom:calc(100% + 10px);left:50%;transform:translateX(-50%);white-space:nowrap;font-family:'Outfit',sans-serif;font-size:.68rem;font-weight:600;color:#fff;background:#08264d;padding:5px 10px;border-radius:6px;opacity:0;pointer-events:none;transition:opacity .2s ease}
.fc__social-link:hover .fc__social-tip{opacity:1}
.fc__visual{margin-bottom:22px}
.fc__visual-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:22px}
.fc__visual-item{padding:20px;background:#FAFAF8;border-radius:16px;border:1px solid #EDE9E0}
.fc__visual-icon{width:44px;height:44px;border-radius:12px;background:rgba(220,174,66,.12);color:#b5882a;display:flex;align-items:center;justify-content:center;margin-bottom:14px}
.fc__visual-title{font-family:'Cormorant Garamond',serif;font-size:1.1rem;font-weight:700;color:#08264d;margin-bottom:8px}
.fc__opp-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:22px}
.fc__opp-item{padding:22px;background:linear-gradient(160deg,rgba(20,92,197,.04),rgba(220,174,66,.04));border-radius:16px;border:1px solid rgba(8,38,77,.06)}
.fc__opp-icon{width:40px;height:40px;border-radius:10px;background:#08264d;color:#dcae42;display:flex;align-items:center;justify-content:center;margin-bottom:14px}
.pt__confirm-note{text-align:center;margin-top:40px;font-family:'Outfit',sans-serif;font-size:.82rem;color:rgba(255,255,255,.4);letter-spacing:.02em}
.pt__confirm-note em{font-style:italic;color:#C6973F}
@media (max-width:960px){.fc{padding:80px 28px}.fc__identity{grid-template-columns:1fr 1fr}.fc__vm,.fc__two-col{grid-template-columns:1fr}.fc__cond-grid{grid-template-columns:1fr}.fc__visual-grid,.fc__opp-grid{grid-template-columns:1fr}}
@media (max-width:560px){.fc__identity{grid-template-columns:1fr}.fc__panel{padding:28px 22px}}
body { font-family: 'Outfit', sans-serif; background: #080808; color: #fff; margin: 0; }
</style>
</head>
<body>
<section class="fc" id="concours" aria-labelledby="fc-title">
    <div class="fc__pattern" aria-hidden="true"></div>
    <div class="fc__wrap">
        <div class="fc__head">
            <span class="fc__eyebrow"><span class="fc__eyebrow-dot"></span> Miss Aurora RDC · Édition officielle</span>
            <h2 class="fc__title" id="fc-title">La beauté au service <em>du changement</em></h2>
            <div class="fc__bar"></div>
            <p class="fc__lead">Miss Aurora RDC est un concours national de beauté, de leadership et d'engagement social organisé par <strong><?= esc($siteName) ?></strong>. Il vise à révéler, former et accompagner les jeunes femmes congolaises capables d'incarner les valeurs d'excellence, de responsabilité et d'impact communautaire.</p>
            <p class="fc__lead" style="margin-top:14px;">Au-delà d'un concours de beauté, Miss Aurora RDC constitue une plateforme de développement personnel, de leadership féminin et de représentation internationale de la République Démocratique du Congo.</p>
            <p class="fc__lead" style="margin-top:14px;font-style:italic;">Le mot « Aurora » signifie l'aube. Il symbolise la lumière, l'espoir, le renouveau et l'émergence d'une nouvelle génération de femmes leaders capables d'apporter une contribution positive à la société.</p>
        </div>
        <div class="fc__identity">
            <div class="fc__id-card"><span class="fc__id-label">Devise</span><p class="fc__id-value">« La beauté au service du changement »</p></div>
            <div class="fc__id-card"><span class="fc__id-label">Slogan</span><p class="fc__id-value">« Révéler la lumière qui inspire l'avenir »</p></div>
            <div class="fc__id-card"><span class="fc__id-label">Ville de la finale</span><p class="fc__id-value">Kinshasa, RDC</p></div>
            <div class="fc__id-card"><span class="fc__id-label">Couleurs officielles</span><p class="fc__id-value">Or · Blanc · Bleu Royal</p></div>
        </div>
        <div class="fc__panel fc__panel--wide fc__visual">
            <h3 class="fc__panel-title"><span class="fc__panel-num">✦</span> Identité visuelle</h3>
            <div class="fc__visual-grid">
                <div class="fc__visual-item"><div class="fc__visual-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11.562 3.266a.5.5 0 0 1 .876 0L15.39 8.87a1 1 0 0 0 1.516.294L21.183 5.5a.5.5 0 0 1 .798.519l-2.834 10.246a1 1 0 0 1-.956.734H5.81a1 1 0 0 1-.957-.734L2.02 6.02a.5.5 0 0 1 .798-.519l4.276 3.664a1 1 0 0 0 1.516-.294z"/><path d="M5 21h14"/></svg></div><h4 class="fc__visual-title">Le logo</h4><p class="fc__vm-text" style="color:#666;">Une couronne stylisée associée à une lumière d'aurore, symbolisant l'espoir, l'élégance et l'excellence.</p></div>
                <div class="fc__visual-item"><div class="fc__visual-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 21h14"/><path d="M12 3v6"/><path d="m8 13 4-4 4 4"/><path d="M5 21c1-4 3-7 7-7s6 3 7 7"/></svg></div><h4 class="fc__visual-title">La couronne</h4><p class="fc__vm-text" style="color:#666;">Symbole du leadership, de la responsabilité et de l'excellence féminine portées par chaque candidate.</p></div>
                <div class="fc__visual-item"><div class="fc__visual-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="4"/><path d="M3 9h18"/></svg></div><h4 class="fc__visual-title">Charte graphique</h4><p class="fc__vm-text" style="color:#666;">Conforme à l'identité visuelle de Miss Aurora RDC et de <?= esc($siteName) ?>, sur tous les supports officiels.</p></div>
            </div>
        </div>
        <div class="fc__vm">
            <div class="fc__vm-card"><div class="fc__vm-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg></div><h3 class="fc__vm-title">Notre vision</h3><p class="fc__vm-text">Faire de Miss Aurora RDC une référence nationale et internationale dans la promotion de la beauté intelligente, du leadership féminin et de l'engagement citoyen.</p></div>
            <div class="fc__vm-card"><div class="fc__vm-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 11 18-5v12L3 14v-3Z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg></div><h3 class="fc__vm-title">Notre mission</h3><p class="fc__vm-text">Identifier, former et accompagner les jeunes femmes afin qu'elles deviennent des ambassadrices du développement social, culturel et économique de la RDC, au niveau national et international.</p></div>
        </div>
        <div class="fc__panel fc__panel--wide">
            <h3 class="fc__panel-title"><span class="fc__panel-num">01</span> Valeurs portées par le concours</h3>
            <div class="fc__tags"><span class="fc__tag">Leadership</span><span class="fc__tag">Excellence</span><span class="fc__tag">Discipline</span><span class="fc__tag">Respect</span><span class="fc__tag">Engagement social</span><span class="fc__tag">Patriotisme</span></div>
        </div>
        <div class="fc__panel fc__panel--wide">
            <h3 class="fc__panel-title"><span class="fc__panel-num">02</span> Conditions de participation</h3>
            <div class="fc__cond-grid">
                <div class="fc__cond-item"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 6 9 17l-5-5"/></svg>Être de nationalité congolaise ou résidente en RDC</div>
                <div class="fc__cond-item"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 6 9 17l-5-5"/></svg>Être âgée de 18 à 28 ans</div>
                <div class="fc__cond-item"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 6 9 17l-5-5"/></svg>Être célibataire</div>
                <div class="fc__cond-item"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 6 9 17l-5-5"/></svg>Avoir une bonne moralité</div>
                <div class="fc__cond-item"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 6 9 17l-5-5"/></svg>Porter la volonté d'un projet social</div>
                <div class="fc__cond-item"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 6 9 17l-5-5"/></svg>Accepter le règlement officiel</div>
            </div>
            <div class="fc__docs"><span class="fc__docs-label">Documents demandés :</span>Copie de la pièce d'identité · Photos récentes · Fiche d'inscription · Curriculum Vitae · Lettre de motivation</div>
        </div>
        <div class="fc__panel fc__panel--wide">
            <h3 class="fc__panel-title"><span class="fc__panel-num">03</span> Déroulement du concours</h3>
            <div class="fc__steps">
                <div class="fc__step"><span class="fc__step-num">1</span><span>Lancement officiel</span></div>
                <div class="fc__step"><span class="fc__step-num">2</span><span>Inscriptions</span></div>
                <div class="fc__step"><span class="fc__step-num">3</span><span>Présélections</span></div>
                <div class="fc__step"><span class="fc__step-num">4</span><span>Castings</span></div>
                <div class="fc__step"><span class="fc__step-num">5</span><span>Formation et coaching</span></div>
                <div class="fc__step"><span class="fc__step-num">6</span><span>Soirée de présentation</span></div>
                <div class="fc__step"><span class="fc__step-num">7</span><span>Finale nationale</span></div>
                <div class="fc__step fc__step--gold"><span class="fc__step-num">8</span><span>Couronnement</span></div>
            </div>
            <div class="fc__docs" style="margin-top:24px;"><span class="fc__docs-label">Calendrier :</span>selon le programme annuel de l'organisation — finale à Kinshasa.</div>
        </div>
        <div class="fc__panel fc__panel--wide fc__opp">
            <h3 class="fc__panel-title"><span class="fc__panel-num">04</span> Opportunités offertes aux lauréates</h3>
            <div class="fc__opp-grid">
                <div class="fc__opp-item"><div class="fc__opp-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11.562 3.266a.5.5 0 0 1 .876 0L15.39 8.87a1 1 0 0 0 1.516.294L21.183 5.5a.5.5 0 0 1 .798.519l-2.834 10.246a1 1 0 0 1-.956.734H5.81a1 1 0 0 1-.957-.734L2.02 6.02a.5.5 0 0 1 .798-.519l4.276 3.664a1 1 0 0 0 1.516-.294z"/><path d="M5 21h14"/></svg></div><p class="fc__vm-text" style="color:#4a4a4a;">La gagnante de Miss Aurora RDC devient l'<strong>ambassadrice officielle</strong> du concours et représente la RDC dans les compétitions internationales partenaires.</p></div>
                <div class="fc__opp-item"><div class="fc__opp-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><path d="M16 3.128a4 4 0 0 1 0 7.744"/><circle cx="9" cy="7" r="4"/></svg></div><p class="fc__vm-text" style="color:#4a4a4a;">Les <strong>dauphines</strong> peuvent aussi être désignées pour représenter la RDC dans des concours internationaux de beauté, leadership, culture et actions sociales.</p></div>
                <div class="fc__opp-item"><div class="fc__opp-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg></div><p class="fc__vm-text" style="color:#4a4a4a;">Ces représentations contribuent au <strong>rayonnement de la femme congolaise</strong> et au renforcement de l'image de la RDC sur la scène internationale.</p></div>
            </div>
        </div>
        <div class="fc__two-col">
            <div class="fc__panel">
                <h3 class="fc__panel-title"><span class="fc__panel-num">05</span> Vote en ligne</h3>
                <p class="fc__vm-text" style="color:#666;margin-bottom:20px;">Le vote en ligne est ouvert au public durant toute la compétition, via un système de paiement sécurisé. <span style="color:#08264d;font-weight:600;">Prix du vote : à définir.</span></p>
                <div class="fc__pay-row">
                    <span class="fc__pay"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><rect x="2" y="5" width="20" height="14" rx="3"/><path d="M2 10h20"/></svg>Orange Money</span>
                    <span class="fc__pay"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><rect x="2" y="5" width="20" height="14" rx="3"/><path d="M2 10h20"/></svg>Airtel Money</span>
                    <span class="fc__pay"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><rect x="2" y="5" width="20" height="14" rx="3"/><path d="M2 10h20"/></svg>M-Pesa</span>
                    <span class="fc__pay"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><rect x="2" y="5" width="20" height="14" rx="3"/><path d="M2 10h20"/></svg>AfriMoney</span>
                    <span class="fc__pay"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><rect x="1" y="4" width="22" height="16" rx="3"/><path d="M1 9h22"/></svg>Carte bancaire</span>
                    <span class="fc__pay"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20"/></svg>Paiement en ligne</span>
                </div>
            </div>
            <div class="fc__panel">
                <h3 class="fc__panel-title"><span class="fc__panel-num">06</span> Billetterie</h3>
                <p class="fc__vm-text" style="color:#666;margin-bottom:20px;">Assistez à la soirée de finale grâce à nos billets, disponibles en trois formules.</p>
                <div class="fc__pay-row">
                    <span class="fc__pay fc__pay--std"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M3 10a2 2 0 0 0 0 4v3a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1v-3a2 2 0 0 1 0-4V7a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1Z"/></svg>Standard</span>
                    <span class="fc__pay fc__pay--vip"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M3 10a2 2 0 0 0 0 4v3a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1v-3a2 2 0 0 1 0-4V7a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1Z"/></svg>VIP</span>
                    <span class="fc__pay fc__pay--vvip"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M11.562 3.266a.5.5 0 0 1 .876 0L15.39 8.87a1 1 0 0 0 1.516.294L21.183 5.5a.5.5 0 0 1 .798.519l-2.834 10.246a1 1 0 0 1-.956.734H5.81a1 1 0 0 1-.957-.734L2.02 6.02a.5.5 0 0 1 .798-.519l4.276 3.664a1 1 0 0 0 1.516-.294z"/></svg>VVIP</span>
                </div>
            </div>
        </div>
        <div class="fc__social">
            <span class="fc__social-label">Suivez Miss Aurora RDC</span>
            <div class="fc__social-links">
                <a href="https://facebook.com/MissAuroraRDC" target="_blank" rel="noopener" class="fc__social-link" aria-label="Facebook — Miss Aurora RDC"><svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M22 12a10 10 0 1 0-11.6 9.9v-7H7.9V12h2.5V9.8c0-2.5 1.5-3.9 3.8-3.9 1.1 0 2.2.2 2.2.2v2.4h-1.3c-1.2 0-1.6.8-1.6 1.6V12h2.8l-.4 2.9h-2.4v7A10 10 0 0 0 22 12Z"/></svg><span class="fc__social-tip">Miss Aurora RDC</span></a>
                <a href="https://instagram.com/MissAuroraRDC" target="_blank" rel="noopener" class="fc__social-link" aria-label="Instagram — Miss Aurora RDC"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1"/></svg><span class="fc__social-tip">Miss Aurora RDC</span></a>
                <a href="https://tiktok.com/@MissAuroraRDC" target="_blank" rel="noopener" class="fc__social-link" aria-label="TikTok — Miss Aurora RDC"><svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M16.6 5.8a4.5 4.5 0 0 1-3.3-1.4v10.2a4.9 4.9 0 1 1-4.2-4.9v2.2a2.7 2.7 0 1 0 1.9 2.6V2h2.2a4.5 4.5 0 0 0 3.4 3.7Z"/></svg><span class="fc__social-tip">Miss Aurora RDC</span></a>
                <a href="https://youtube.com/@MissAuroraRDC" target="_blank" rel="noopener" class="fc__social-link" aria-label="YouTube — Miss Aurora RDC"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22.5 12s0-3.2-.4-4.7a3 3 0 0 0-2.1-2.1C18.5 4.8 12 4.8 12 4.8s-6.5 0-8 .4A3 3 0 0 0 1.9 7.3C1.5 8.8 1.5 12 1.5 12s0 3.2.4 4.7a3 3 0 0 0 2.1 2.1c1.5.4 8 .4 8 .4s6.5 0 8-.4a3 3 0 0 0 2.1-2.1c.4-1.5.4-4.7.4-4.7Z"/><path d="m9.8 15 5.2-3-5.2-3Z"/></svg><span class="fc__social-tip">Miss Aurora RDC</span></a>
            </div>
        </div>
    </div>
</section>
</body>
</html>
