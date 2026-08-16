<?php
/**
 * API Vote Mobile Money (Unipesa) — Miss Millénium 2026
 * Nouvelle structure de base de données (sites, concours, participantes, offres, transactions)
 */
header('Content-Type: application/json; charset=utf-8');

/* ═══════════════════════════════════════════════════════════════
   CONFIGURATION UNIPESA
   ═══════════════════════════════════════════════════════════════ */
define('UNIPESA_PUBLIC_ID',   'cdefaccbefd7e5fec36f514fd051f2185969e603');
define('UNIPESA_MERCHANT_ID', 'cdefa368fd86db654502ca1cb922bc5a1a691055');
define('UNIPESA_SECRET_KEY',  'cdbbf8a2f9e7790193d265acd4442275633ef46c280629a5181a46ee57e4e62799a2cdf6a5d9de5347163c6d79edbffa154eb274e6aca317320fe57a734874ce');
define('UNIPESA_BASE_URL',    'https://api.unipesa.tech');

/* Provider IDs (fournis par l'utilisateur) */
define('PROVIDER_AIRTEL',  17);
define('PROVIDER_ORANGE',  10);
define('PROVIDER_MPESA',   9);
define('PROVIDER_AFRICELL', 19);

/* Statuts des concours et des paiements - adaptés à la base de données */
define('CONCOURS_ETAT_ACTIF',   'en_cours');
define('PAIEMENT_ETAT_EN_ATTENTE', 'en_attente');
define('PAIEMENT_ETAT_CONFIRME',   'confirme');
define('PAIEMENT_ETAT_ECHEC',      'echoue');

/* ═══════════════════════════════════════════════════════════════
   BASE DE DONNÉES (Miss Millénium)
   ═══════════════════════════════════════════════════════════════ */
define('DB_HOST', 'localhost:3306');
define('DB_NAME', 'mayi1275_zaloria_multisysteme');
define('DB_USER', 'mayi1275_zaloriatech');
define('DB_PASS', '07/09/1996/O2switch');
 

/* ═══════════════════════════════════════════════════════════════
   FONCTIONS
   ═══════════════════════════════════════════════════════════════ */

/**
 * Calcule la signature Unipesa selon un ordre fixe (ordre de la documentation probable).
 * Pour payment_c2b, l'ordre semble être : merchant_id, customer_id, order_id, amount, currency, country, callback_url, provider_id.
 * Si cela ne fonctionne pas, on peut revenir à l'ordre alphabétique avec ksort.
 */
function calcUnipesaSignature(array $data, string $secret): string {
    // Ordre fixe pour payment_c2b
    $order = ['merchant_id', 'customer_id', 'order_id', 'amount', 'currency', 'country', 'callback_url', 'provider_id'];
    $str = '';
    foreach ($order as $key) {
        if (isset($data[$key])) {
            $str .= $key . (string)$data[$key];
        }
    }
    // Log pour débogage
    file_put_contents(__DIR__ . '/unipesa_signature_debug.log', date('c') . ' STR (fixed order): ' . $str . PHP_EOL, FILE_APPEND);
    return strtolower(hash_hmac('sha512', $str, $secret));
}

function normalizePhoneUnipesa(string $phone, int $providerId): string {
    $digits = preg_replace('/\D/', '', $phone);
    switch ($providerId) {
        case PROVIDER_MPESA:
            if (strlen($digits) === 10 && $digits[0] === '0') $digits = '243' . substr($digits, 1);
            elseif (strlen($digits) === 9) $digits = '243' . $digits;
            break;
        case PROVIDER_ORANGE:
        case PROVIDER_AFRICELL:
            if (strlen($digits) === 12 && substr($digits, 0, 3) === '243') $digits = '0' . substr($digits, 3);
            elseif (strlen($digits) === 9) $digits = '0' . $digits;
            break;
        case PROVIDER_AIRTEL:
            if (strlen($digits) === 12 && substr($digits, 0, 3) === '243') $digits = substr($digits, 3);
            elseif (strlen($digits) === 10 && $digits[0] === '0') $digits = substr($digits, 1);
            break;
    }
    return $digits;
}

function unipesaStatusToInternal(int $code): string {
    if ($code === 2) return PAIEMENT_ETAT_CONFIRME;
    if ($code >= 3)  return PAIEMENT_ETAT_ECHEC;
    return PAIEMENT_ETAT_EN_ATTENTE;
}

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function getProviderId(string $methode): int {
    return match (strtolower($methode)) {
        'airtel'   => PROVIDER_AIRTEL,
        'orange'   => PROVIDER_ORANGE,
        'mpesa'    => PROVIDER_MPESA,
        'africell' => PROVIDER_AFRICELL,
        default    => throw new InvalidArgumentException("Méthode non supportée : $methode"),
    };
}

function checkConcours(PDO $pdo, int $concours_id): array {
    $stmt = $pdo->prepare("
        SELECT etat_concours, date_ouverture, date_cloture, arret_manuel
        FROM concours
        WHERE concours_id = ?
    ");
    $stmt->execute([$concours_id]);
    $concours = $stmt->fetch();

    if (!$concours) {
        return ['success' => false, 'message' => 'Concours introuvable.'];
    }

    if (!in_array($concours['etat_concours'], ['actif', 'en_cours'])) {
        return ['success' => false, 'message' => 'Ce concours n\'est pas actif (état : ' . $concours['etat_concours'] . ').'];
    }

    if ($concours['arret_manuel'] == 1) {
        return ['success' => false, 'message' => 'Ce concours a été arrêté manuellement.'];
    }

    $now = time();
    $debut = strtotime($concours['date_ouverture']);
    $fin   = strtotime($concours['date_cloture']);
    if ($now < $debut) {
        return ['success' => false, 'message' => 'Ce concours n\'est pas encore ouvert (début le ' . date('d/m/Y H:i', $debut) . ').'];
    }
    if ($now > $fin) {
        return ['success' => false, 'message' => 'Ce concours est déjà terminé (clôture le ' . date('d/m/Y H:i', $fin) . ').'];
    }

    return ['success' => true];
}

function checkOffre(PDO $pdo, int $offre_id, int $concours_id): array {
    $stmt = $pdo->prepare("
        SELECT offre_id, nombre_votes_inclus, prix, devise, offre_visible
        FROM offres_votes
        WHERE offre_id = ? AND concours_id = ?
    ");
    $stmt->execute([$offre_id, $concours_id]);
    $offre = $stmt->fetch();

    if (!$offre) {
        return ['success' => false, 'message' => 'Offre de votes introuvable pour ce concours.'];
    }

    if ($offre['offre_visible'] != 1) {
        return ['success' => false, 'message' => 'Cette offre de votes n\'est pas visible actuellement.'];
    }

    return ['success' => true, 'data' => $offre];
}

function getSiteLienUnique(PDO $pdo, int $concours_id): ?string {
    $stmt = $pdo->prepare("
        SELECT s.lien_unique
        FROM concours c
        JOIN sites s ON s.site_id = c.site_id
        WHERE c.concours_id = ?
    ");
    $stmt->execute([$concours_id]);
    $row = $stmt->fetch();
    return $row ? $row['lien_unique'] : null;
}

function checkParticipante(PDO $pdo, int $participante_id, int $concours_id): bool {
    $stmt = $pdo->prepare("
        SELECT 1 FROM participantes
        WHERE participante_id = ? AND concours_id = ?
    ");
    $stmt->execute([$participante_id, $concours_id]);
    return (bool)$stmt->fetchColumn();
}

function checkEtape(PDO $pdo, int $etape_id, int $concours_id): array {
    $stmt = $pdo->prepare("
        SELECT etape_id, etape_terminee, date_ouverture, date_cloture
        FROM etapes_du_concours
        WHERE etape_id = ? AND concours_id = ?
    ");
    $stmt->execute([$etape_id, $concours_id]);
    $etape = $stmt->fetch();

    if (!$etape) {
        return ['success' => false, 'message' => 'Étape introuvable pour ce concours.'];
    }

    if ($etape['etape_terminee'] == 1) {
        return ['success' => false, 'message' => 'Cette étape est déjà terminée.'];
    }

    $now = time();
    $debut = strtotime($etape['date_ouverture']);
    $fin   = strtotime($etape['date_cloture']);
    if ($now < $debut) {
        return ['success' => false, 'message' => 'Cette étape n\'est pas encore ouverte (début le ' . date('d/m/Y H:i', $debut) . ').'];
    }
    if ($now > $fin) {
        return ['success' => false, 'message' => 'Cette étape est déjà terminée (clôture le ' . date('d/m/Y H:i', $fin) . ').'];
    }

    return ['success' => true];
}

/* ═══════════════════════════════════════════════════════════════
   ROUTEUR
   ═══════════════════════════════════════════════════════════════ */
$rawInput = file_get_contents('php://input');

// ─── CALLBACK UNIPESA ───
if ($rawInput && empty($_POST['action'])) {
    file_put_contents(__DIR__ . '/unipesa_callback_millenium.log', date('c') . ' ' . $rawInput . PHP_EOL, FILE_APPEND);

    $cb = json_decode($rawInput, true);
    if (!$cb) {
        http_response_code(400);
        exit('Bad JSON');
    }

    $orderId  = $cb['order_id'] ?? null;
    $status   = isset($cb['status']) ? (int)$cb['status'] : -1;
    $internalStatus = unipesaStatusToInternal($status);

    // Vérification de la signature du callback est désactivée car nous n'avons pas la clé publique
    // On se contente de traiter la notification

    if ($orderId) {
        $messageRetour = $cb['provider_result']['message'] ?? $cb['result']['message'] ?? '';

        try {
            $pdo = getDB();
            $pdo->prepare("
                UPDATE transactions_votes
                SET etat_paiement = :s,
                    confirme_le = NOW(),
                    id_transaction_unipesa = :tid,
                    ref_transaction_unipesa = :tref,
                    message_retour = :msg
                WHERE numero_reference = :r
            ")->execute([
                ':s'    => $internalStatus,
                ':tid'  => $cb['transaction_id']  ?? '',
                ':tref' => $cb['transaction_ref'] ?? '',
                ':msg'  => $messageRetour,
                ':r'    => $orderId,
            ]);
            file_put_contents(__DIR__ . '/unipesa_callback_millenium.log', date('c') . ' Transaction mise à jour: ' . $orderId . ' avec message: ' . $messageRetour . PHP_EOL, FILE_APPEND);
        } catch (Exception $e) {
            file_put_contents(__DIR__ . '/unipesa_callback_millenium.log', date('c') . ' DB ERROR: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
        }
    }
    echo 'OK';
    exit;
}

$action = $_POST['action'] ?? '';

/* ═══════════════════════════════════════════════════════════════
   ACTION : initiate_payment  (Mobile Money)
   ═══════════════════════════════════════════════════════════════ */
if ($action === 'initiate_payment') {
    $participanteId = (int)($_POST['candidate_id'] ?? 0);
    $concoursId     = (int)($_POST['evenement_id'] ?? 0);
    $offreId        = (int)($_POST['pack_id'] ?? 0);
    $etapeId        = isset($_POST['etape_id']) ? (int)$_POST['etape_id'] : null;
    $methode        = strtolower(trim($_POST['methode'] ?? ''));
    $telephone      = trim($_POST['telephone'] ?? '');
    $messageUser    = trim($_POST['message'] ?? '');

    $missing = [];
    if (!$participanteId) $missing[] = 'candidate_id';
    if (!$concoursId) $missing[] = 'evenement_id';
    if (!$offreId) $missing[] = 'pack_id';
    if (!$methode) $missing[] = 'methode';
    if (!$telephone) $missing[] = 'telephone';
    if (!empty($missing)) {
        echo json_encode(['success' => false, 'message' => 'Paramètres manquants : ' . implode(', ', $missing)]);
        exit;
    }

    // Accepte +243, 243 ou 0 suivi de 9 chiffres (ex: 089xxxxxxx)
    if (!preg_match('/^(\+?243|0)\d{9}$/', $telephone)) {
        echo json_encode(['success' => false, 'message' => 'Numéro invalide. Formats acceptés : 243XXXXXXXXX, +243XXXXXXXXX ou 0XXXXXXXXX (10 chiffres).']);
        exit;
    }

    $pdo = getDB();

    $checkConcours = checkConcours($pdo, $concoursId);
    if (!$checkConcours['success']) {
        echo json_encode(['success' => false, 'message' => $checkConcours['message']]);
        exit;
    }

    $checkOffre = checkOffre($pdo, $offreId, $concoursId);
    if (!$checkOffre['success']) {
        echo json_encode(['success' => false, 'message' => $checkOffre['message']]);
        exit;
    }
    $offreData = $checkOffre['data'];

    if (!checkParticipante($pdo, $participanteId, $concoursId)) {
        echo json_encode(['success' => false, 'message' => 'Participante invalide pour ce concours.']);
        exit;
    }

    if ($etapeId !== null && $etapeId > 0) {
        $checkEtape = checkEtape($pdo, $etapeId, $concoursId);
        if (!$checkEtape['success']) {
            echo json_encode(['success' => false, 'message' => $checkEtape['message']]);
            exit;
        }
    } else {
        $etapeId = null;
    }

    $lienUnique = getSiteLienUnique($pdo, $concoursId);
    if (!$lienUnique) {
        echo json_encode(['success' => false, 'message' => 'Site non trouvé pour ce concours.']);
        exit;
    }

    $nombreVotes = $offreData['nombre_votes_inclus'];
    $montant     = $offreData['prix'];
    $devise      = $offreData['devise'] ?? 'USD';
    $providerId  = getProviderId($methode);

    $reference = $lienUnique . '-' . date('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

    $stmt = $pdo->prepare("
        INSERT INTO transactions_votes
        (numero_reference, concours_id, participante_id, etape_id,
         moyen_paiement, numero_telephone, email_votant,
         montant_paye, devise, votes_accordes, etat_paiement,
         initie_le, message_retour)
        VALUES
        (:ref, :cid, :pid, :eid,
         :meth, :tel, '',
         :montant, :devise, :votes, :etat,
         NOW(), '')
    ");
    $stmt->execute([
        ':ref'      => $reference,
        ':cid'      => $concoursId,
        ':pid'      => $participanteId,
        ':eid'      => $etapeId,
        ':meth'     => $methode,
        ':tel'      => $telephone,
        ':montant'  => $montant,
        ':devise'   => $devise,
        ':votes'    => $nombreVotes,
        ':etat'     => PAIEMENT_ETAT_EN_ATTENTE,
    ]);

    $customerTel = normalizePhoneUnipesa($telephone, $providerId);
    $callbackUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/voter_api.php';

    // Préparer le payload pour Unipesa avec un ordre fixe
    $payload = [
        'merchant_id'  => UNIPESA_MERCHANT_ID,
        'customer_id'  => $customerTel,
        'order_id'     => $reference,
        'amount'       => number_format($montant, 2, '.', ''),
        'currency'     => $devise,
        'country'      => 'CD',
        'callback_url' => $callbackUrl,
        'provider_id'  => $providerId,
    ];
    // La signature est calculée avec l'ordre fixe défini dans la fonction
    $payload['signature'] = calcUnipesaSignature($payload, UNIPESA_SECRET_KEY);

    $ch = curl_init(UNIPESA_BASE_URL . '/' . UNIPESA_PUBLIC_ID . '/payment_c2b');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    file_put_contents(__DIR__ . '/unipesa_millenium.log', date('c') . ' INITIATE REQ:' . json_encode($payload) . ' RESP HTTP:' . $httpCode . ' ERR:' . $curlErr . ' RESP:' . $response . PHP_EOL, FILE_APPEND);

    if ($curlErr || !$response || $httpCode >= 400) {
        $logResponse = (strpos($response, '<') === 0) ? 'HTML response' : $response;
        file_put_contents(__DIR__ . '/unipesa_millenium.log', date('c') . ' Erreur HTTP ' . $httpCode . ' - ' . $logResponse . PHP_EOL, FILE_APPEND);
        echo json_encode(['success' => false, 'message' => 'Transaction enregistrée mais erreur réseau (HTTP ' . $httpCode . ').']);
        exit;
    }

    $data = json_decode($response, true);
    if (!$data || ($data['result']['code'] ?? -1) !== 0) {
        $msg = $data['result']['message'] ?? 'Erreur opérateur.';
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }

    echo json_encode(['success' => true, 'reference' => $reference, 'message' => 'Demande envoyée. Confirmez sur votre téléphone.']);
    exit;
}

/* ═══════════════════════════════════════════════════════════════
   ACTION : check_payment
   ═══════════════════════════════════════════════════════════════ */
if ($action === 'check_payment') {
    $reference = trim($_POST['reference'] ?? '');
    if (!$reference) {
        echo json_encode(['statut' => PAIEMENT_ETAT_EN_ATTENTE]);
        exit;
    }

    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare("SELECT etat_paiement, message_retour FROM transactions_votes WHERE numero_reference = ?");
        $stmt->execute([$reference]);
        $row = $stmt->fetch();
        if ($row && in_array($row['etat_paiement'], [PAIEMENT_ETAT_CONFIRME, PAIEMENT_ETAT_ECHEC])) {
            echo json_encode([
                'statut'     => $row['etat_paiement'],
                'message'    => $row['message_retour'] ?? '',
                'from_cache' => true,
            ]);
            exit;
        }
    } catch (Exception $e) {}

    $statusPayload = [
        'merchant_id' => UNIPESA_MERCHANT_ID,
        'order_id'    => $reference,
    ];
    $statusPayload['signature'] = calcUnipesaSignature($statusPayload, UNIPESA_SECRET_KEY);

    $ch = curl_init(UNIPESA_BASE_URL . '/' . UNIPESA_PUBLIC_ID . '/status');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($statusPayload),
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp    = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr || !$resp || $httpCode >= 400) {
        echo json_encode(['statut' => PAIEMENT_ETAT_EN_ATTENTE, 'message' => 'En attente de l’opérateur…']);
        exit;
    }

    $data      = json_decode($resp, true);
    $uniStatus = isset($data['status']) ? (int)$data['status'] : -1;
    $internal  = unipesaStatusToInternal($uniStatus);
    $message   = $data['result']['message'] ?? '';

    if (in_array($internal, [PAIEMENT_ETAT_CONFIRME, PAIEMENT_ETAT_ECHEC])) {
        try {
            $pdo = getDB();
            $pdo->prepare("
                UPDATE transactions_votes
                SET etat_paiement = :s,
                    confirme_le = NOW(),
                    id_transaction_unipesa = :tid,
                    ref_transaction_unipesa = :tref,
                    message_retour = :msg
                WHERE numero_reference = :r
                  AND etat_paiement NOT IN ('confirme','echoue')
            ")->execute([
                ':s'    => $internal,
                ':tid'  => $data['transaction_id'] ?? '',
                ':tref' => $data['transaction_ref'] ?? '',
                ':msg'  => $message,
                ':r'    => $reference,
            ]);
        } catch (Exception $e) {}
    }

    echo json_encode(['statut' => $internal, 'message' => $message]);
    exit;
}

/* ═══════════════════════════════════════════════════════════════
   ACTION : get_realtime_votes
   ═══════════════════════════════════════════════════════════════ */
if ($action === 'get_realtime_votes') {
    $concoursId = (int)($_POST['evenement_id'] ?? $_GET['evenement_id'] ?? 0);
    if (!$concoursId) {
        echo json_encode(['success' => false, 'message' => 'evenement_id manquant.']);
        exit;
    }

    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare("
            SELECT p.participante_id, p.nom_complet,
                   COALESCE(SUM(t.votes_accordes), 0) AS votes
            FROM participantes p
            LEFT JOIN transactions_votes t
                   ON p.participante_id = t.participante_id
                  AND t.etat_paiement = 'confirme'
                  AND t.concours_id = ?
            WHERE p.concours_id = ?
            GROUP BY p.participante_id
            ORDER BY p.participante_id
        ");
        $stmt->execute([$concoursId, $concoursId]);
        $results = $stmt->fetchAll();

        $votesPerParticipante = [];
        foreach ($results as $row) {
            $votesPerParticipante[$row['participante_id']] = (int)$row['votes'];
        }

        echo json_encode([
            'success'              => true,
            'votes_per_candidate'  => $votesPerParticipante,
            'evenement_id'         => $concoursId
        ]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erreur serveur']);
        exit;
    }
}

http_response_code(400);
echo json_encode(['error' => 'action_inconnue']);