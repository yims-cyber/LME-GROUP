<?php
/**
 * Cron de synchronisation des ventes Chariow
 * Exécution recommandée : toutes les heures ou une fois par jour
 * 
 * Usage : php cron_sync_sales.php
 */

require_once __DIR__ . '/vote_chariow_api.php';

// ---- Configuration ----------------------------------------------------------
$startDate = null;

// Récupérer la dernière date de synchro
$lastSync = getLastSyncDate();
if ($lastSync) {
    $startDate = $lastSync;
} else {
    // Si jamais synchronisé, on prend les 7 derniers jours
    $startDate = date('Y-m-d', strtotime('-7 days'));
}

// Pour éviter de manquer des ventes, on commence un jour avant la dernière synchro
$startDate = date('Y-m-d', strtotime($startDate . ' -1 day'));

logPulse("CRON: Démarrage avec start_date={$startDate}");

// ---- Récupération des ventes ------------------------------------------------
$sales = getSalesList($startDate);
logPulse("CRON: " . count($sales) . " ventes récupérées depuis {$startDate}");

// ---- Traitement -------------------------------------------------------------
$pdo = getDB();
$processed = 0;
foreach ($sales as $sale) {
    if (processSale($sale, $pdo)) {
        $processed++;
    }
}

// ---- Sauvegarde de la date --------------------------------------------------
$today = date('Y-m-d');
saveLastSyncDate($today);

logPulse("CRON: Terminé. {$processed} transactions mises à jour.");
echo "OK - {$processed} traitées\n";