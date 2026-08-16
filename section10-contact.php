<?php
function esc($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
$siteName = 'LME GROUP';
$contactPhone = '+243 860 370 727';
$contactEmail = 'actutara@gmail.com';
$contactAddress = '40, Avenue Kasangulu, Commune de Kasa-Vubu' . "\n" . 'Kinshasa, RDC';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Contact — <?= esc($siteName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,700;1,300;1,700&family=Outfit:wght@300;400;500;600;700&family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ═══════ CONTACT ═══════ */
.sec { background: #0A0A0A; padding: 96px 0; position: relative; }
.sec-wrap { max-width: 1300px; margin: 0 auto; padding: 0 40px; }
.sec-label { display: inline-flex; align-items: center; gap: 10px; font-size: .6rem; font-weight: 700; letter-spacing: .26em; text-transform: uppercase; color: var(--gold); margin-bottom: 14px; }
.sec-title { font-family: 'Cormorant Garamond', serif; font-size: clamp(2rem, 4.5vw, 4rem); font-weight: 300; line-height: 1; letter-spacing: -.02em; color: #fff; margin-bottom: 12px; text-align: center; }
.sec-title em { font-style: italic; font-weight: 700; color: var(--gold-lt); }
.sec-bar { width: 38px; height: 2px; background: linear-gradient(90deg, var(--gold), var(--gold-lt)); border-radius: 2px; margin: 14px auto 18px; }
.contact-grid { display: grid; grid-template-columns: 1fr 1.2fr; gap: 64px; align-items: start; margin-top: 52px; }
.contact-items { display: flex; flex-direction: column; gap: 18px; margin-bottom: 32px; }
.contact-item { display: flex; align-items: flex-start; gap: 14px; }
.ico { width: 44px; height: 44px; border-radius: 10px; background: var(--gold-dim); border: 1px solid var(--gold-bdr); display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: var(--gold); }
.ico svg { width: 18px; height: 18px; stroke: currentColor; stroke-width: 2; fill: none; }
.contact-item__lab { font-size: .6rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: var(--muted2); margin-bottom: 3px; }
.contact-item__val { font-size: .88rem; color: #fff; }
.contact-item__val a { color: #fff; transition: color .2s; }
.contact-item__val a:hover { color: var(--gold-light); }
.socials { display: flex; gap: 9px; flex-wrap: wrap; }
.soc-btn { display: inline-flex; align-items: center; gap: 7px; padding: 9px 16px; border-radius: 8px; background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.07); font-size: .7rem; font-weight: 600; color: var(--muted); transition: all .22s; text-decoration: none; }
.soc-btn:hover { background: var(--gold-dim); border-color: var(--gold-border); color: var(--gold-light); }
.contact-form { background: rgba(255,255,255,.025); border: 1px solid rgba(255,255,255,.06); border-radius: 18px; padding: 36px; }
.contact-form h3 { font-family: 'Cormorant Garamond', serif; font-size: 1.7rem; font-weight: 700; color: #fff; margin-bottom: 24px; }
.contact-form h3 em { font-style: italic; color: var(--gold-lt); }
.fg-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.fg { margin-bottom: 16px; }
.fg label { display: block; font-size: .62rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: var(--muted2); margin-bottom: 7px; }
.fg input, .fg select, .fg textarea { display: block; width: 100%; padding: 12px 15px; border-radius: 9px; background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.09); color: #fff; font-family: 'Montserrat', sans-serif; font-size: .82rem; font-weight: 300; outline: none; transition: border-color .22s, background .22s; }
.fg textarea { resize: vertical; height: 90px; }
.fg select { appearance: none; cursor: pointer; }
.fg input::placeholder, .fg textarea::placeholder { color: rgba(255,255,255,.4); }
.fg input:focus, .fg textarea:focus, .fg select:focus { border-color: rgba(201,168,76,.4); background: rgba(201,168,76,.04); }
.btn { width: 100%; justify-content: center; display: inline-flex; align-items: center; gap: 8px; font-family: 'Montserrat', sans-serif; font-size: .72rem; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; padding: 12px 26px; border-radius: 8px; border: none; cursor: pointer; transition: all .25s; }
.btn-gold { background: var(--gold); color: #000; }
.btn-gold:hover { background: var(--gold-light); transform: translateY(-1px); box-shadow: 0 8px 20px rgba(201,168,76,.3); }

/* Responsive contact */
@media (max-width: 992px) { #contact .contact-grid { grid-template-columns: 1fr !important; gap: 40px !important; } }
@media (max-width: 768px) {
    #contact { padding: 60px 16px !important; }
    #contact .sec-wrap { padding: 0 !important; }
    #contact .contact-grid { gap: 32px !important; margin-top: 32px !important; }
    #contact .contact-form { padding: 20px !important; }
    #contact .fg-row { grid-template-columns: 1fr !important; gap: 8px !important; }
    #contact .contact-items { gap: 12px !important; margin-bottom: 24px !important; }
    #contact .socials { gap: 8px !important; }
    #contact .socials a { flex: 1 1 auto !important; justify-content: center !important; padding: 8px 12px !important; font-size: 0.65rem !important; }
    #contact .contact-item .ico { width: 36px !important; height: 36px !important; }
    #contact .contact-item__val { font-size: 0.8rem !important; }
}

:root { --black: #080808; --gold: #C9A84C; --gold-light: #E8CC80; --gold-dim: rgba(201,168,76,.15); --gold-border: rgba(201,168,76,.32); --gold-bdr: var(--gold-border); --muted: rgba(255,255,255,.46); --muted2: rgba(255,255,255,.4); --bg2: #0A0A0A; --gold-lt: var(--gold-light); }
body { font-family: 'Outfit', sans-serif; background: var(--black); color: #fff; margin: 0; }
</style>
</head>
<body>
<section class="sec" id="contact">
    <div class="sec-wrap">
        <div style="text-align:center;opacity:1;transform:none;">
            <div class="sec-label">Nous rejoindre</div>
            <h2 class="sec-title">Contact &amp; <em style="font-style:italic;font-weight:700;color:var(--gold-lt);">Inscription</em></h2>
            <div class="sec-bar"></div>
        </div>
        <div class="contact-grid">
            <div>
                <div class="contact-items">
                    <div class="contact-item">
                        <div class="ico"><svg viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12s-8-6-8-10a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg></div>
                        <div>
                            <div class="contact-item__lab">Adresse</div>
                            <div class="contact-item__val"><?= nl2br(esc($contactAddress)) ?></div>
                        </div>
                    </div>
                    <div class="contact-item">
                        <div class="ico"><svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.27h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.9a16 16 0 0 0 6.09 6.09l.91-.91a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg></div>
                        <div>
                            <div class="contact-item__lab">Téléphone / WhatsApp</div>
                            <div class="contact-item__val"><a href="https://wa.me/243860370727" target="_blank"><?= esc($contactPhone) ?></a></div>
                        </div>
                    </div>
                    <div class="contact-item">
                        <div class="ico"><svg viewBox="0 0 24 24"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg></div>
                        <div>
                            <div class="contact-item__lab">Email</div>
                            <div class="contact-item__val"><a href="mailto:<?= esc($contactEmail) ?>"><?= esc($contactEmail) ?></a></div>
                        </div>
                    </div>
                </div>
                <div class="socials">
                    <a href="#" class="soc-btn">Facebook</a>
                    <a href="#" class="soc-btn">Instagram</a>
                    <a href="https://wa.me/243828277768" target="_blank" class="soc-btn">WhatsApp</a>
                    <a href="candidatures.php" class="soc-btn">Candidatures</a>
                </div>
            </div>
            <div class="contact-form">
                <h3>Envoyez-nous <em style="font-style:italic;color:var(--gold-lt);">un message</em></h3>
                <div class="fg-row">
                    <div class="fg"><label for="c-prenom">Prénom</label><input type="text" id="c-prenom" name="prenom" placeholder="Votre prénom"></div>
                    <div class="fg"><label for="c-nom">Nom</label><input type="text" id="c-nom" name="nom" placeholder="Votre nom"></div>
                </div>
                <div class="fg"><label for="c-email">Email</label><input type="email" id="c-email" name="email" placeholder="votre@email.com"></div>
                <div class="fg"><label for="c-sujet">Objet</label><select id="c-sujet" name="objet"><option value="">Sélectionnez un objet…</option><option>Proposition de projet</option><option>Partenariat / Sponsoring</option><option>Information générale</option><option>Presse / Médias</option></select></div>
                <div class="fg"><label for="c-message">Message</label><textarea id="c-message" name="message" placeholder="Votre message…"></textarea></div>
                <button class="btn btn-gold" type="button" onclick="handleSubmit(this)">Envoyer le message →</button>
            </div>
        </div>
    </div>
</section>
<script>
function handleSubmit(btn) {
    btn.textContent = '✓ Message envoyé !';
    btn.style.background = '#22c55e';
    setTimeout(() => { btn.textContent = 'Envoyer le message →'; btn.style.background = ''; }, 3200);
}
</script>
</body>
</html>
