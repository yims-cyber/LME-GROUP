-- Migration v2 - Fix pour production Maishapay + payment_page_url + gestion declined
-- Tous nouveaux champs NULL DEFAULT NULL pour compat

-- Ajoute payment_page_url pour stocker URL CyberSource retournée par Maishapay PROD (ex: https://pcesarakapayprodapi01.../CyberSource)
ALTER TABLE transactions_votes
ADD COLUMN IF NOT EXISTS payment_page_url VARCHAR(512) NULL DEFAULT NULL AFTER est_paiement_maishapay;

-- S'assure que moyen_paiement a visa/mastercard
ALTER TABLE transactions_votes 
MODIFY COLUMN moyen_paiement ENUM('mpesa','airtel','orange','africell','carte','especes','manuel','visa','mastercard','maishapay_card','maishapay') NOT NULL DEFAULT 'carte';

-- S'assure que gateway et autres existent avec NULL
ALTER TABLE transactions_votes ADD COLUMN IF NOT EXISTS gateway_paiement ENUM('unipesa','maishapay') NULL DEFAULT NULL AFTER moyen_paiement;
ALTER TABLE transactions_votes ADD COLUMN IF NOT EXISTS provider_maishapay VARCHAR(32) NULL DEFAULT NULL AFTER gateway_paiement;
ALTER TABLE transactions_votes ADD COLUMN IF NOT EXISTS est_paiement_maishapay TINYINT(1) NULL DEFAULT NULL AFTER provider_maishapay;

-- Corrige lignes désalignées anciennes (ex: moyen_paiement=000000000)
-- Détecte lignes où moyen_paiement est un numéro et email_votant est un montant
UPDATE transactions_votes SET moyen_paiement='visa', gateway_paiement='maishapay', provider_maishapay='VISA', est_paiement_maishapay=1 WHERE moyen_paiement='000000000' AND message_retour LIKE '%VISA%';
UPDATE transactions_votes SET moyen_paiement='mastercard', gateway_paiement='maishapay', provider_maishapay='MASTERCARD', est_paiement_maishapay=1 WHERE moyen_paiement='000000000' AND message_retour LIKE '%MASTER%';

-- Nettoie références avec slash final
UPDATE transactions_votes SET numero_reference=TRIM(TRAILING '/' FROM numero_reference) WHERE numero_reference LIKE '%/';

-- Met à jour transactions en_attente depuis plus de 30 min qui sont en fait declined (pour débloquer)
-- Ne pas exécuter automatiquement en prod sans vérifier, mais utile pour tests
-- UPDATE transactions_votes SET etat_paiement='echoue', message_retour=CONCAT(message_retour, ' - Timeout auto echoue') WHERE etat_paiement='en_attente' AND initie_le < DATE_SUB(NOW(), INTERVAL 30 MINUTE) AND moyen_paiement IN ('visa','mastercard','carte');
