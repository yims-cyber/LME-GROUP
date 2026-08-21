-- Migration MaishaPay Carte - vote1 - VERSION SECURISEE NULL PAR DEFAUT
-- Objectif: éviter erreurs pour structures qui n'ont pas option carte
-- Tous les nouveaux champs acceptent NULL par défaut

-- 1) Étend enum moyen_paiement (ajoute visa, mastercard)
-- Garde NOT NULL mais ajoute valeurs pour analyse directe
-- Si votre table a déjà VARCHAR, sautez cette étape
ALTER TABLE `transactions_votes` 
MODIFY COLUMN `moyen_paiement` ENUM('mpesa','airtel','orange','africell','carte','especes','manuel','visa','mastercard','maishapay_card','maishapay') NOT NULL DEFAULT 'carte';

-- 2) Ajoute gateway pour distinguer unipesa vs maishapay - NULL par défaut pour compat autres structures
-- Utilise IF NOT EXISTS si MySQL 8+, sinon supprime IF NOT EXISTS
ALTER TABLE `transactions_votes`
ADD COLUMN IF NOT EXISTS `gateway_paiement` ENUM('unipesa','maishapay') NULL DEFAULT NULL AFTER `moyen_paiement`;

-- Fallback sans IF NOT EXISTS si erreur:
-- ALTER TABLE `transactions_votes` ADD COLUMN `gateway_paiement` ENUM('unipesa','maishapay') NULL DEFAULT NULL AFTER `moyen_paiement`;

-- 3) Provider Maishapay (VISA, MASTERCARD, AMEX, etc - beaucoup de providers possibles)
ALTER TABLE `transactions_votes`
ADD COLUMN IF NOT EXISTS `provider_maishapay` VARCHAR(32) NULL DEFAULT NULL AFTER `gateway_paiement`;

-- 4) Flag boolean rapide pour analyse carte Maishapay - NULL par défaut, 1=maishapay carte, 0=unipesa, NULL=ancien/non défini
-- Pour analyse: WHERE est_paiement_maishapay=1 (cartes), WHERE est_paiement_maishapay=0 (mobile), WHERE est_paiement_maishapay IS NULL (ancien)
ALTER TABLE `transactions_votes`
ADD COLUMN IF NOT EXISTS `est_paiement_maishapay` TINYINT(1) NULL DEFAULT NULL AFTER `provider_maishapay`;

-- 5) Index pour analyses (optionnel, ignore si existe déjà)
-- ALTER TABLE `transactions_votes` ADD INDEX `idx_gateway` (`gateway_paiement`);
-- ALTER TABLE `transactions_votes` ADD INDEX `idx_est_maishapay` (`est_paiement_maishapay`);
-- ALTER TABLE `transactions_votes` ADD INDEX `idx_provider_maishapay` (`provider_maishapay`);

-- 6) Corrige la transaction de test qui était en en_attente (APPROVED) - exemple
UPDATE `transactions_votes` 
SET `moyen_paiement`='visa',
    `gateway_paiement`='maishapay',
    `provider_maishapay`='VISA',
    `est_paiement_maishapay`=1,
    `etat_paiement`='confirme',
    `confirme_le`=NOW(),
    `id_transaction_unipesa`='264435',
    `ref_transaction_unipesa`='2RJ1-1787337571',
    `message_retour`='APPROVED - VISA Maishapay Checkout - Test OK'
WHERE `numero_reference`='lme-group-CARD-20260821203927-31159E';

-- 7) Pour structures sans carte, les anciens enregistrements restent NULL = pas d'erreur
-- Exemples requêtes analyse sécurisées (gèrent NULL):
-- Toutes cartes Maishapay (nouveau)
-- SELECT * FROM transactions_votes WHERE est_paiement_maishapay=1;
-- Toutes cartes y compris ancien 'carte' + nouveau visa/mastercard
-- SELECT * FROM transactions_votes WHERE moyen_paiement IN ('carte','visa','mastercard') OR est_paiement_maishapay=1;
-- Par provider (gère NULL)
-- SELECT COALESCE(provider_maishapay, moyen_paiement) as provider, COUNT(*), SUM(votes_accordes) FROM transactions_votes WHERE etat_paiement='confirme' GROUP BY provider;
-- Cartes vs Mobile vs Ancien
-- SELECT 
--   CASE 
--     WHEN est_paiement_maishapay=1 THEN 'maishapay_carte'
--     WHEN est_paiement_maishapay=0 THEN 'unipesa_mobile'
--     WHEN gateway_paiement='maishapay' THEN 'maishapay'
--     WHEN gateway_paiement='unipesa' THEN 'unipesa'
--     ELSE 'ancien_non_defini'
--   END as source,
--   COUNT(*), SUM(votes_accordes) 
-- FROM transactions_votes WHERE etat_paiement='confirme' GROUP BY source;

-- Version ultra-safe sans IF NOT EXISTS (pour MySQL <8):
/*
ALTER TABLE `transactions_votes` 
MODIFY COLUMN `moyen_paiement` ENUM('mpesa','airtel','orange','africell','carte','especes','manuel','visa','mastercard','maishapay_card','maishapay') NOT NULL DEFAULT 'carte';

ALTER TABLE `transactions_votes`
ADD COLUMN `gateway_paiement` ENUM('unipesa','maishapay') NULL DEFAULT NULL AFTER `moyen_paiement`;

ALTER TABLE `transactions_votes`
ADD COLUMN `provider_maishapay` VARCHAR(32) NULL DEFAULT NULL AFTER `gateway_paiement`;

ALTER TABLE `transactions_votes`
ADD COLUMN `est_paiement_maishapay` TINYINT(1) NULL DEFAULT NULL AFTER `provider_maishapay`;
*/
