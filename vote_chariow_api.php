<?php
/**
 * Miss Nationale RDC 2026 — API Vote Chariow
 * Trois packs : 50 votes 20$, 100 votes 40$, 250 votes 100$
 * Product IDs correspondants :
 *   prd_l0acc81d = 20$ (50 votes)
 *   prd_s5lp9n9m = 40$ (100 votes)
 *   prd_df9zsyhd = 100$ (250 votes)
 */

/* ═══════════════════════════════════════════════
   CONFIGURATION
   ═══════════════════════════════════════════════ */
define('CHARIOW_STORE', 'vvgmkcxh.mychariow.shop');
define('CHARIOW_API_KEY', 'sk_plpow1fp_fdface362ea1a80ffdf28e7416541ec5');
define('CHARIOW_API_BASE', 'https://api.chariow.com/v1');

define('DB_HOST', getenv('DB_HOST') ?: 'localhost:3306');
define('DB_NAME', getenv('DB_NAME') ?: 'mayi1275_zaloria_multisysteme');
define('DB_USER', getenv('DB_USER') ?: 'mayi1275_zaloriatech');
define('DB_PASS', getenv('DB_PASS') ?: '07/09/1996/O2switch');

// Tableau des packs : ID => [votes, usd, product_id]
$CHARIOW_PRODUCTS = [
    50  => 'prd_l0acc81d',   // 50 votes, 20$
    100 => 'prd_s5lp9n9m',   // 100 votes, 40$
    250 => 'prd_df9zsyhd',   // 250 votes, 100$
];

$PACKS = [
    50  => ['votes' => 50,  'usd' => 20],
    100 => ['votes' => 100, 'usd' => 40],
    250 => ['votes' => 250, 'usd' => 100],
];

/* ═══════════════════════════════════════════════
   BASE DE DONNÉES
   ═══════════════════════════════════════════════ */
function getDB(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        } catch (PDOException $e) {
            logError('DB_CONNECT', $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Erreur base de données.']);
            exit;
        }
    }
    return $pdo;
}

/* ═══════════════════════════════════════════════
   LOGGING
   ═══════════════════════════════════════════════ */
function logError(string $ctx, string $msg): void
{
    file_put_contents(__DIR__ . '/chariow_errors.log', date('c') . " [{$ctx}] {$msg}\n", FILE_APPEND);
}

function logPulse(string $msg): void
{
    file_put_contents(__DIR__ . '/chariow_pulse.log', date('c') . " {$msg}\n", FILE_APPEND);
}

function logWebhookRaw(string $raw): void
{
    file_put_contents(__DIR__ . '/webhook_raw.log', date('c') . " HTTP200 {$raw}\n", FILE_APPEND);
}

/* ═══════════════════════════════════════════════
   MATCHING PAR DONNÉES CLIENT (email + téléphone)
   ═══════════════════════════════════════════════ */
function findReferenceByCustomerData(array $pulse): ?string
{
    $email = $pulse['customer']['email'] ?? '';
    $phone = $pulse['customer']['phone'] ?? '';
    $productId = $pulse['product']['id'] ?? '';

    if (!$email || !$phone || !$productId) {
        return null;
    }

    $cleanPhone = preg_replace('/^243/', '', ltrim($phone, '+'));

    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("
            SELECT reference
            FROM vote_transactions
            WHERE methode = 'chariow_snap'
              AND statut = 'pending'
              AND telephone = :phone
              AND cree_le >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            ORDER BY cree_le DESC
            LIMIT 1
        ");
        $stmt->execute([':phone' => $phone]);
        $ref = $stmt->fetchColumn();

        if (!$ref && $cleanPhone !== $phone) {
            $stmt->execute([':phone' => $cleanPhone]);
            $ref = $stmt->fetchColumn();
        }

        if ($ref) {
            logPulse("REF_VIA_CUSTOMER_MATCH email={$email} phone={$phone} ref={$ref}");
            return $ref;
        }
    } catch (PDOException $e) {
        logError('CUSTOMER_MATCH', $e->getMessage());
    }
    return null;
}

/* ═══════════════════════════════════════════════
   EXTRACTION RÉFÉRENCE (pour le webhook)
   ═══════════════════════════════════════════════ */
function extractInternalRef(array $pulse): ?string
{
    $sale = $pulse['sale'] ?? [];
    $saleId = $sale['id'] ?? '';

    // 1. sale_id déjà enregistré
    if ($saleId) {
        try {
            $stmt = getDB()->prepare("SELECT reference FROM vote_transactions WHERE chariow_sale_id = ? LIMIT 1");
            $stmt->execute([$saleId]);
            $ref = $stmt->fetchColumn();
            if ($ref) {
                logPulse("REF_VIA_SALE_ID sale={$saleId} ref={$ref}");
                return (string)$ref;
            }
        } catch (PDOException $e) {
            logError('EXTRACT_REF_DB', $e->getMessage());
        }
    }

    // 2. custom_metadata (notre référence interne)
    $metadata = $sale['custom_metadata'] ?? [];
    if (is_array($metadata) && isset($metadata['internal_ref'])) {
        $ref = trim($metadata['internal_ref']);
        if (preg_match('/^MISSRDC-CHR-/i', $ref)) {
            logPulse("REF_VIA_METADATA ref={$ref}");
            return strtoupper($ref);
        }
    }

    // 3. custom_fields (ancien)
    $customFields = $sale['custom_fields'] ?? [];
    if (is_array($customFields)) {
        foreach ($customFields as $field) {
            $name  = strtolower($field['name']  ?? '');
            $value = trim($field['value'] ?? '');
            if (in_array($name, ['internal_ref','ref','reference'], true)
                && preg_match('/^MISSRDC-CHR-/i', $value)) {
                logPulse("REF_VIA_CUSTOM_FIELDS ref={$value}");
                return strtoupper($value);
            }
        }
    }

    // 4. email encodé
    $email = $pulse['customer']['email'] ?? '';
    if ($email && preg_match('/^ref:([A-Z0-9\-]+)@snap\.vote$/i', $email, $m)) {
        logPulse("REF_VIA_EMAIL ref={$m[1]}");
        return strtoupper($m[1]);
    }

    // 5. matching client
    return findReferenceByCustomerData($pulse);
}

/* ═══════════════════════════════════════════════
   HELPERS AMÉLIORÉS
   ═══════════════════════════════════════════════ */
function extractErrorMessage(array $sale): ?string
{
    $directKeys = [
        'failure_reason', 'error_message', 'message', 'error',
        'reason', 'decline_reason', 'description'
    ];
    foreach ($directKeys as $k) {
        if (!empty($sale[$k]) && is_string($sale[$k])) {
            $v = trim($sale[$k]);
            if (strlen($v) > 3 && !in_array(strtolower($v), ['failed','error','ok','null'])) {
                return $v;
            }
        }
    }

    $pd = $sale['payment_details'] ?? [];
    if (is_array($pd)) {
        foreach (['error', 'failure_reason', 'message', 'decline_reason'] as $k) {
            if (!empty($pd[$k]) && is_string($pd[$k])) {
                $v = trim($pd[$k]);
                if (strlen($v) > 3) return $v;
            }
        }
    }

    if (!empty($sale['error']) && is_array($sale['error'])) {
        $err = $sale['error'];
        if (!empty($err['message']) && is_string($err['message'])) {
            return trim($err['message']);
        }
    }
    return null;
}

function normalizePaymentMethod(array $sale): string
{
    $candidates = [];

    foreach (['payment_method','methode','channel','gateway','provider','type'] as $k) {
        if (!empty($sale[$k]) && is_string($sale[$k])) {
            $candidates[] = strtolower(trim($sale[$k]));
        }
    }
    $pd = $sale['payment_details'] ?? [];
    if (is_array($pd)) {
        foreach (['type','method','provider','channel'] as $k) {
            if (!empty($pd[$k]) && is_string($pd[$k])) {
                $candidates[] = strtolower(trim($pd[$k]));
            }
        }
    }

    $filtered = array_filter($candidates, function($v) {
        return !in_array($v, ['', 'unknown', 'inconnu', 'null', 'undefined']);
    });
    return !empty($filtered) ? reset($filtered) : '';
}

/**
 * Récupère les détails d'une vente via l'API Chariow
 */
function getSaleDetails(string $saleId): ?array
{
    $url = CHARIOW_API_BASE . "/sales/{$saleId}";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . CHARIOW_API_KEY,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        logError('GET_SALE_DETAILS', "HTTP {$httpCode} for sale {$saleId}");
        return null;
    }
    $data = json_decode($response, true);
    return $data['data'] ?? null;
}

/* ═══════════════════════════════════════════════
   TRANSACTIONS
   ═══════════════════════════════════════════════ */
function insertTransaction(array $d): bool
{
    try {
        $stmt = getDB()->prepare("
            INSERT INTO vote_transactions
                (reference, candidate_id, votes, montant_fc, currency, methode, telephone, statut, cree_le)
            VALUES
                (:ref, :cid, :votes, :montant_fc, :currency, :methode, :tel, 'pending', NOW())
        ");
        return $stmt->execute([
            ':ref'        => $d['reference'],
            ':cid'        => (int)$d['candidate_id'],
            ':votes'      => (int)$d['votes'],
            ':montant_fc' => (int)($d['montant_fc'] ?? 0),
            ':currency'   => $d['currency']  ?? 'USD',
            ':methode'    => $d['methode']   ?? 'chariow_snap',
            ':tel'        => !empty($d['telephone']) ? trim($d['telephone']) : null,
        ]);
    } catch (PDOException $e) {
        logError('INSERT_TX', $e->getMessage());
        return false;
    }
}

function confirmAndCredit(PDO $pdo, string $ref, string $saleId = '', string $method = ''): bool
{
    try {
        $pdo->prepare("
            UPDATE vote_transactions
            SET statut       = 'confirmed',
                confirme_le  = NOW(),
                chariow_sale_id = COALESCE(NULLIF(:sid,''), chariow_sale_id),
                methode      = CASE WHEN :pm <> '' THEN :pm2 ELSE methode END,
                error_message = NULL
            WHERE reference = :r AND statut <> 'confirmed'
        ")->execute([':sid' => $saleId, ':pm' => $method, ':pm2' => $method, ':r' => $ref]);

        $row = $pdo->query(
            "SELECT candidate_id, votes FROM vote_transactions WHERE reference = " . $pdo->quote($ref)
        )->fetch();

        if ($row && (int)$row['votes'] > 0 && (int)$row['candidate_id'] > 0) {
            $pdo->prepare(
                "UPDATE candidates SET votes_total = COALESCE(votes_total,0) + :v WHERE id = :id"
            )->execute([':v' => (int)$row['votes'], ':id' => (int)$row['candidate_id']]);
        }
        logPulse("CREDITED ref={$ref} votes=" . ($row['votes'] ?? 0));
        return true;
    } catch (PDOException $e) {
        logError('CONFIRM', $e->getMessage());
        return false;
    }
}

function markAsFailed(PDO $pdo, string $ref, string $event, string $saleId = '', string $method = '', ?string $errMsg = null): bool
{
    try {
        $stmt = $pdo->prepare("
            UPDATE vote_transactions
            SET statut      = 'failed',
                confirme_le = NOW(),
                chariow_sale_id = COALESCE(NULLIF(:sid,''), chariow_sale_id),
                methode     = CASE WHEN :pm <> '' THEN :pm2 ELSE methode END,
                error_message = COALESCE(:err, error_message)
            WHERE reference = :r AND statut IN ('pending','failed')
        ");
        $stmt->execute([':sid' => $saleId, ':pm' => $method, ':pm2' => $method, ':err' => $errMsg, ':r' => $ref]);
        logPulse("FAILED ref={$ref} event={$event}");
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        logError('MARK_FAILED', $e->getMessage());
        return false;
    }
}

/* ═══════════════════════════════════════════════
   FONCTIONS DE SYNCHRONISATION (pour le cron)
   ═══════════════════════════════════════════════ */

/**
 * Récupère la liste des ventes depuis l'API Chariow avec pagination
 * @param string $startDate Date de début (Y-m-d)
 * @param string|null $cursor Curseur de pagination
 * @param int $perPage Nombre d'éléments par page (max 100)
 * @return array Tableau de ventes (liste complète)
 */
function getSalesList(string $startDate, ?string $cursor = null, int $perPage = 100): array
{
    $allSales = [];
    do {
        $url = CHARIOW_API_BASE . "/sales?per_page={$perPage}&start_date={$startDate}";
        if ($cursor) {
            $url .= "&cursor=" . urlencode($cursor);
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . CHARIOW_API_KEY,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            logError('GET_SALES_LIST', "HTTP {$httpCode} - URL: {$url}");
            break;
        }
        $data = json_decode($response, true);
        if (!isset($data['data']['data']) || !is_array($data['data']['data'])) {
            break;
        }
        $sales = $data['data']['data'];
        $allSales = array_merge($allSales, $sales);
        $pagination = $data['data']['pagination'] ?? [];
        $cursor = $pagination['next_cursor'] ?? null;
    } while ($cursor);

    return $allSales;
}

/**
 * Traite une vente individuelle (recherche de correspondance et mise à jour)
 * @param array $saleSummary Résumé de la vente (depuis la liste)
 * @param PDO $pdo Connexion DB
 * @return bool True si traité
 */
function processSale(array $saleSummary, PDO $pdo): bool
{
    $saleId = $saleSummary['id'] ?? '';
    if (!$saleId) {
        return false;
    }

    // 1. Vérifier si le sale_id est déjà connu
    $stmt = $pdo->prepare("SELECT reference, statut FROM vote_transactions WHERE chariow_sale_id = ? LIMIT 1");
    $stmt->execute([$saleId]);
    $existing = $stmt->fetch();

    if ($existing) {
        // Si déjà confirmé ou failed, on ignore
        if (in_array($existing['statut'], ['confirmed', 'failed'])) {
            return false;
        }
        $ref = $existing['reference'];
    } else {
        // 2. Essayer de trouver par email/téléphone
        $ref = findReferenceByCustomerData($saleSummary);
        if (!$ref) {
            logPulse("CRON: Aucune correspondance pour sale {$saleId} - ignoré");
            return false;
        }
    }

    // 3. Récupérer les détails complets de la vente
    $details = getSaleDetails($saleId);
    if (!$details) {
        logError('CRON_DETAILS', "Impossible de récupérer les détails pour sale {$saleId}");
        return false;
    }

    // 4. Extraire les informations
    $status = $details['status'] ?? '';
    $method = normalizePaymentMethod($details);
    $errorMsg = extractErrorMessage($details);

    // 5. Mettre à jour la transaction
    try {
        if ($status === 'completed' || $status === 'settled') {
            confirmAndCredit($pdo, $ref, $saleId, $method);
        } elseif (in_array($status, ['failed', 'abandoned'])) {
            markAsFailed($pdo, $ref, $status, $saleId, $method, $errorMsg);
        } else {
            // Autres statuts : on met à jour le sale_id et la méthode
            $pdo->prepare("
                UPDATE vote_transactions
                SET chariow_sale_id = :sid,
                    methode = COALESCE(NULLIF(:m,''), methode)
                WHERE reference = :r
            ")->execute([':sid' => $saleId, ':m' => $method, ':r' => $ref]);
        }
        logPulse("CRON: Vente {$saleId} traitée, ref={$ref}, statut={$status}");
        return true;
    } catch (Exception $e) {
        logError('CRON_UPDATE', "Erreur pour ref {$ref}: " . $e->getMessage());
        return false;
    }
}

/**
 * Sauvegarde la date de dernière synchronisation
 * @param string $date Date au format Y-m-d
 */
function saveLastSyncDate(string $date): void
{
    file_put_contents(__DIR__ . '/last_sync_date.txt', $date);
}

/**
 * Récupère la date de dernière synchronisation
 * @return string|null Date au format Y-m-d ou null
 */
function getLastSyncDate(): ?string
{
    $file = __DIR__ . '/last_sync_date.txt';
    if (file_exists($file)) {
        $content = file_get_contents($file);
        return trim($content) ?: null;
    }
    return null;
}

/* ═══════════════════════════════════════════════
   WEBHOOK CHARIOW
   ═══════════════════════════════════════════════ */
$rawInput = file_get_contents('php://input');
if ($rawInput && !isset($_POST['action'])) {
    logWebhookRaw($rawInput);

    $pulse = json_decode($rawInput, true);
    if (!$pulse) { http_response_code(400); echo 'Bad JSON'; exit; }

    $event  = $pulse['event'] ?? '';
    $sale   = $pulse['sale']  ?? [];
    $saleId = $sale['id']     ?? '';
    $method = normalizePaymentMethod($sale);

    $ref = extractInternalRef($pulse);
    logPulse("WEBHOOK event={$event} sale={$saleId} ref=" . ($ref ?? 'NOT_FOUND') . " email=" . ($pulse['customer']['email'] ?? ''));

    if (!$ref) {
        logPulse("NO_REF sale={$saleId} event={$event} checkout_url=" . ($pulse['checkout']['url'] ?? 'absent'));
        http_response_code(200);
        echo 'OK';
        exit;
    }

    // Si la méthode détectée est vide, on conserve l'ancienne
    if (empty($method)) {
        try {
            $old = getDB()->prepare("SELECT methode FROM vote_transactions WHERE reference=? LIMIT 1");
            $old->execute([$ref]);
            $method = $old->fetchColumn() ?: 'chariow_snap';
        } catch (PDOException $e) { $method = 'chariow_snap'; }
    }

    // Sauvegarde du sale_id
    if ($saleId) {
        try {
            getDB()->prepare("UPDATE vote_transactions SET chariow_sale_id = COALESCE(NULLIF(:sid,''), chariow_sale_id) WHERE reference = :r")
                   ->execute([':sid' => $saleId, ':r' => $ref]);
        } catch (PDOException $e) {
            logError('SAVE_SALE_ID_WEBHOOK', $e->getMessage());
        }
    }

    // Enrichissement via l'API GET /v1/sales/{sale_id}
    $enrichedMethod = '';
    $enrichedError  = null;
    if ($saleId) {
        $details = getSaleDetails($saleId);
        if ($details !== null) {
            // Méthode de paiement
            if (!empty($details['payment']['method']['name'])) {
                $enrichedMethod = strtolower(trim($details['payment']['method']['name']));
            }
            // Message d'erreur (priorité au message client en français)
            if (!empty($details['payment']['failure_error']['customer_message'])) {
                $enrichedError = trim($details['payment']['failure_error']['customer_message']);
            } elseif (!empty($details['payment']['failure_error']['message'])) {
                $enrichedError = trim($details['payment']['failure_error']['message']);
            }
        }
    }

    // On utilise la méthode enrichie si trouvée, sinon celle du webhook (ou l'ancienne)
    $finalMethod = $enrichedMethod ?: $method;
    // On combine les messages d'erreur éventuels
    $errorMsg = $enrichedError ?? extractErrorMessage($sale);

    try {
        $pdo = getDB();
        if ($event === 'successful.sale') {
            confirmAndCredit($pdo, $ref, $saleId, $finalMethod);
        } elseif (in_array($event, ['failed.sale', 'abandoned.sale'])) {
            markAsFailed($pdo, $ref, $event, $saleId, $finalMethod, $errorMsg);
        }
    } catch (Exception $e) {
        logError('WEBHOOK', $e->getMessage());
    }

    http_response_code(200);
    echo 'OK';
    exit;
}

/* ═══════════════════════════════════════════════
   ACTIONS AJAX
   ═══════════════════════════════════════════════ */
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
$action = trim($_POST['action'] ?? '');

/* ── initiate_chariow ──────────────────────────── */
if ($action === 'initiate_chariow') {
    $packId    = (int)($_POST['pack_id']      ?? 0);
    $votes     = (int)($_POST['votes']        ?? 0);
    $amountUSD = (float)($_POST['amount_usd'] ?? 0);
    $candidateId = (int)($_POST['candidate_id'] ?? 0);
    $concoursId  = (int)($_POST['concours_id'] ?? 0);
    $etapeId     = isset($_POST['etape_id']) ? (int)$_POST['etape_id'] : null;
    $phone     = trim($_POST['phone']         ?? '');
    $email     = trim($_POST['email']         ?? '');
    // Les champs first_name, last_name, country_code ne sont plus requis ici car on utilise l'email uniquement
    // Mais l'API Chariow demande un first_name et last_name. On les déduit de l'email ?
    // On va utiliser l'email comme nom pour simplifier, ou on peut les envoyer vides.
    // On peut aussi les récupérer depuis un champ de formulaire si présent, mais dans le code actuel ils ne sont pas envoyés.
    // Pour éviter l'erreur, on envoie des valeurs par défaut.
    $firstName = trim($_POST['first_name'] ?? 'Votant');
    $lastName  = trim($_POST['last_name']  ?? 'Chariow');
    $country   = trim($_POST['country_code'] ?? 'CD');

    if (!$packId || !isset($PACKS[$packId])) {
        echo json_encode(['success' => false, 'message' => 'Pack invalide.']); exit;
    }
    $pack = $PACKS[$packId];
    if ((int)$votes !== (int)$pack['votes'] || abs($amountUSD - $pack['usd']) > 0.01) {
        echo json_encode(['success' => false, 'message' => 'Incohérence du pack.']); exit;
    }
    if (!$candidateId) {
        echo json_encode(['success' => false, 'message' => 'Candidate introuvable.']); exit;
    }
    try {
        $chk = getDB()->prepare("SELECT id FROM candidates WHERE id=? AND statut='actif' AND supprime_le IS NULL");
        $chk->execute([$candidateId]);
        if (!$chk->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Candidate non disponible.']); exit;
        }
    } catch (PDOException $e) {
        logError('CAND_CHECK', $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Erreur vérification.']); exit;
    }

    $productId = $CHARIOW_PRODUCTS[$packId] ?? null;
    if (!$productId) {
        echo json_encode(['success' => false, 'message' => 'Produit non configuré pour ce pack.']); exit;
    }

    $reference  = 'MISSRDC-CHR-' . date('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    $montantCDF = (int)round($amountUSD * 2400);

    // Insertion transaction
    if (!insertTransaction([
        'reference'    => $reference,
        'candidate_id' => $candidateId,
        'votes'        => $pack['votes'],
        'montant_fc'   => $montantCDF,
        'currency'     => 'USD',
        'methode'      => 'chariow_snap',
        'telephone'    => $phone ?: null,
    ])) {
        echo json_encode(['success' => false, 'message' => 'Erreur insertion transaction.']); exit;
    }

    // Appel à l'API Chariow pour initier le checkout avec custom_metadata
    $checkoutPayload = [
        'product_id' => $productId,
        'email'      => $email,
        'first_name' => $firstName,
        'last_name'  => $lastName,
        'phone'      => [
            'number' => $phone,
            'country_code' => $country
        ],
        'custom_metadata' => [
            'internal_ref' => $reference
        ]
    ];
    $ch = curl_init(CHARIOW_API_BASE . '/checkout');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . CHARIOW_API_KEY,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($checkoutPayload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        logError('INITIATE_CHECKOUT', "HTTP {$httpCode} - " . $response);
        echo json_encode(['success' => false, 'message' => 'Erreur lors de l\'initiation du paiement.']); exit;
    }
    $data = json_decode($response, true);
    $checkoutUrl = $data['data']['payment']['checkout_url'] ?? null;
    if (!$checkoutUrl) {
        logError('INITIATE_CHECKOUT', "Pas de checkout_url dans la réponse");
        echo json_encode(['success' => false, 'message' => 'Impossible d\'obtenir l\'URL de paiement.']); exit;
    }

    logPulse("INITIATE ref={$reference} cand={$candidateId} pack={$packId} checkout_url={$checkoutUrl}");

    echo json_encode([
        'success'            => true,
        'reference'          => $reference,
        'chariow_product_id' => $productId,
        'checkout_url'       => $checkoutUrl,
        'votes'              => $pack['votes'],
        'amount_usd'         => $amountUSD,
        'amount_cdf'         => $montantCDF,
    ]);
    exit;
}

/* ── save_sale_id ─────────────────────────────── */
if ($action === 'save_sale_id') {
    $reference = trim($_POST['reference']       ?? '');
    $saleId    = trim($_POST['chariow_sale_id'] ?? '');
    if (!$reference || !$saleId) {
        echo json_encode(['success' => false, 'message' => 'Données manquantes.']); exit;
    }
    try {
        $pdo = getDB();
        $chk = $pdo->prepare("SELECT statut FROM vote_transactions WHERE reference=? LIMIT 1");
        $chk->execute([$reference]);
        $row = $chk->fetch();
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Transaction introuvable.']); exit;
        }
        if ($row['statut'] === 'confirmed') {
            echo json_encode(['success' => true, 'updated' => 0, 'reason' => 'already_confirmed']); exit;
        }
        $upd = $pdo->prepare("UPDATE vote_transactions SET chariow_sale_id = :sid WHERE reference = :ref AND (chariow_sale_id IS NULL OR chariow_sale_id = '')");
        $upd->execute([':sid' => $saleId, ':ref' => $reference]);
        logPulse("SALE_ID_SAVED ref={$reference} sale_id={$saleId}");
        echo json_encode(['success' => true, 'updated' => $upd->rowCount()]);
    } catch (PDOException $e) {
        logError('SAVE_SALE_ID', $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Erreur base de données.']);
    }
    exit;
}

/* ── check_payment ────────────────────────────── */
if ($action === 'check_payment') {
    $reference = trim($_POST['reference'] ?? '');
    if (!$reference) { echo json_encode(['statut' => 'pending']); exit; }
    try {
        $stmt = getDB()->prepare("SELECT statut, votes, candidate_id, methode, chariow_sale_id, error_message FROM vote_transactions WHERE reference=?");
        $stmt->execute([$reference]);
        $row = $stmt->fetch();
        echo json_encode([
            'statut'          => $row ? $row['statut']                : 'pending',
            'votes'           => $row ? (int)$row['votes']            : 0,
            'candidate_id'    => $row ? (int)$row['candidate_id']     : 0,
            'methode'         => $row ? $row['methode']               : '',
            'chariow_sale_id' => $row ? ($row['chariow_sale_id'] ?? '') : '',
            'error_message'   => $row ? ($row['error_message']   ?? '') : '',
        ]);
    } catch (PDOException $e) {
        logError('CHECK_PAYMENT', $e->getMessage());
        echo json_encode(['statut' => 'pending']);
    }
    exit;
}

/* ── update_failed ────────────────────────────── */
if ($action === 'update_failed') {
    $reference = trim($_POST['reference']       ?? '');
    $saleId    = trim($_POST['chariow_sale_id'] ?? '');
    $errMsg    = trim($_POST['error_message']   ?? '');
    if (!$reference) {
        echo json_encode(['success' => false, 'message' => 'Référence manquante.']); exit;
    }
    try {
        $pdo = getDB();
        $cur = $pdo->prepare("SELECT statut FROM vote_transactions WHERE reference=?");
        $cur->execute([$reference]);
        if ($cur->fetchColumn() === 'confirmed') {
            echo json_encode(['success' => true, 'updated' => 0, 'reason' => 'already_confirmed']); exit;
        }
        $upd = $pdo->prepare("UPDATE vote_transactions SET statut='failed', confirme_le=NOW(), chariow_sale_id=COALESCE(NULLIF(:sid,''),chariow_sale_id), error_message=COALESCE(NULLIF(:err,''),error_message) WHERE reference=:r AND statut IN ('pending','failed')");
        $upd->execute([':sid' => $saleId, ':err' => $errMsg, ':r' => $reference]);
        echo json_encode(['success' => true, 'updated' => $upd->rowCount()]);
    } catch (PDOException $e) {
        logError('UPDATE_FAILED', $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Erreur DB.']);
    }
    exit;
}

/* ── update_payment_details ───────────────────── */
if ($action === 'update_payment_details') {
    $reference = trim($_POST['reference']       ?? '');
    $saleId    = trim($_POST['chariow_sale_id'] ?? '');
    $method    = trim($_POST['payment_method']  ?? '');
    $errMsg    = trim($_POST['error_message']   ?? '');
    if (!$reference) {
        echo json_encode(['success' => false, 'message' => 'Référence manquante.']); exit;
    }
    try {
        $pdo = getDB();
        $cur = $pdo->prepare("SELECT statut FROM vote_transactions WHERE reference=?");
        $cur->execute([$reference]);
        if ($cur->fetchColumn() === 'confirmed') {
            echo json_encode(['success' => true, 'updated' => 0, 'reason' => 'already_confirmed']); exit;
        }
        $upd = $pdo->prepare("UPDATE vote_transactions SET chariow_sale_id=COALESCE(NULLIF(:sid,''),chariow_sale_id), methode=COALESCE(NULLIF(:m,''),methode), error_message=COALESCE(NULLIF(:err,''),error_message) WHERE reference=:r");
        $upd->execute([':sid' => $saleId, ':m' => $method, ':err' => $errMsg, ':r' => $reference]);
        echo json_encode(['success' => true, 'updated' => $upd->rowCount()]);
    } catch (PDOException $e) {
        logError('UPDATE_PAYMENT', $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Erreur DB.']);
    }
    exit;
}

/* ── record_view ──────────────────────────────── */
if ($action === 'record_view') {
    $candId = (int)($_POST['cand_id'] ?? 0);
    if ($candId) {
        try {
            getDB()->prepare("INSERT INTO candidate_views (candidate_id, views) VALUES (:id,1) ON DUPLICATE KEY UPDATE views = views + 1")->execute([':id' => $candId]);
        } catch (PDOException $e) { logError('RECORD_VIEW', $e->getMessage()); }
    }
    echo json_encode(['success' => true]);
    exit;
}

/* ── get_realtime_votes ───────────────────────── */
if ($action === 'get_realtime_votes') {
    $concoursId = (int)($_POST['evenement_id'] ?? 0);
    if (!$concoursId) {
        echo json_encode(['success' => false, 'message' => 'ID événement manquant.']);
        exit;
    }
    try {
        $stmt = getDB()->prepare("
            SELECT candidate_id, COALESCE(SUM(votes),0) AS total
            FROM vote_transactions
            WHERE statut='confirmed' AND concours_id = :cid
            GROUP BY candidate_id
        ");
        $stmt->execute([':cid' => $concoursId]);
        $out = [];
        while ($r = $stmt->fetch()) $out[(int)$r['candidate_id']] = (int)$r['total'];
        echo json_encode(['success' => true, 'votes_per_candidate' => $out]);
    } catch (PDOException $e) {
        logError('REALTIME_VOTES', $e->getMessage());
        echo json_encode(['success' => false]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Action inconnue.']);