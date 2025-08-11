<?php
require_once __DIR__ . '/../config/session_init.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/phpqrcode/qrlib.php';

header('Content-Type: application/json');

// Encryption config
define('DTS_CRYPT_KEY', 'PutYourStrongSecretKeyHere01234'); // CHANGE THIS IN PRODUCTION!
define('DTS_CRYPT_METHOD', 'aes-256-cbc');

// Helper to encrypt
function encryptToken($plaintext)
{
    $key = DTS_CRYPT_KEY;
    $ivlen = openssl_cipher_iv_length(DTS_CRYPT_METHOD);
    $iv = openssl_random_pseudo_bytes($ivlen);
    $ciphertext = openssl_encrypt($plaintext, DTS_CRYPT_METHOD, $key, 0, $iv);
    return base64_encode($iv . $ciphertext); // Prefix IV so we can decode
}

// Helper to decrypt
function decryptToken($enc)
{
    $key = DTS_CRYPT_KEY;
    $ivlen = openssl_cipher_iv_length(DTS_CRYPT_METHOD);
    $enc = base64_decode($enc);
    $iv = substr($enc, 0, $ivlen);
    $ciphertext = substr($enc, $ivlen);
    return openssl_decrypt($ciphertext, DTS_CRYPT_METHOD, $key, 0, $iv);
}

function generateRandomToken($length = 32): string
{
    return bin2hex(random_bytes($length / 2));
}

function generateRandomCode($length = 8): string
{
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[random_int(0, $charactersLength - 1)];
    }
    return $randomString;
}

try {
    $userId = SessionManager::get('user')['id'] ?? 0;
    if (!$userId) {
        throw new Exception('Authentication required', 401);
    }

    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
error_log('halo halo');
    $documentId = intval($data['document_id'] ?? 0);
if (!$documentId) {
    throw new Exception('Missing document_id', 400);
    
}
if (!$userId) {
    throw new Exception('Missing user_id', 400);
}


    // Check if document exists and belongs to user
    $stmt = $conn->prepare("SELECT * FROM tbl_documents WHERE document_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $documentId, $userId);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();
    if (!$doc) {
        throw new Exception('Document not found or access denied', 404);
    }

    // Only generate new tokens if they don't exist
    $qrTokenEnc = $doc['qr_token'] ?? '';
    $embedTokenEnc = $doc['embed_token'] ?? '';

    $plainQrToken = '';
    $plainEmbedToken = '';

    if (empty($qrTokenEnc) || empty($embedTokenEnc)) {
        // Generate new tokens (plaintext)
        $plainQrToken = generateRandomToken(64);      // 32 bytes hex = 64 chars
        $plainEmbedToken = generateRandomCode(8);     // 8-char alphanumeric

        // Encrypt them
        $qrTokenEnc = encryptToken($plainQrToken);
        $embedTokenEnc = encryptToken($plainEmbedToken);

        // Save encrypted tokens to DB
        $stmt = $conn->prepare("UPDATE tbl_documents SET qr_token = ?, embed_token = ? WHERE document_id = ?");
        $stmt->bind_param("ssi", $qrTokenEnc, $embedTokenEnc, $documentId);
        if (!$stmt->execute()) {
            throw new Exception('Failed to update document: ' . $conn->error, 500);
        }
    } else {
        // Decrypt existing tokens for return/QR/etc
        $plainQrToken = decryptToken($qrTokenEnc);
        $plainEmbedToken = decryptToken($embedTokenEnc);
    }

    // Generate QR code image (only if not exists)
    function getLocalIpAddress()
    {
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        socket_connect($socket, '8.8.8.8', 53);
        socket_getsockname($socket, $localIp);
        socket_close($socket);
        return $localIp;
    }
    $localIp = getLocalIpAddress();

    $qrDir = __DIR__ . '/../../uploads/qrcodes/';
    if (!file_exists($qrDir)) {
        mkdir($qrDir, 0755, true);
    }
    $qrFilename = "doc_$documentId.png";
    $qrPath = $qrDir . $qrFilename;
    $baseUrl = "http://$localIp/DTS";
    $qrViewUrl = $baseUrl . "/index.php?token=" . urlencode($plainQrToken);

    if (!file_exists($qrPath)) {
        QRcode::png($qrViewUrl, $qrPath, QR_ECLEVEL_L, 6);
    }

    $qrUrl = $baseUrl . "/uploads/qrcodes/$qrFilename";

    echo json_encode([
        'success' => true,
        'message' => 'Codes generated successfully',
        'document_id' => $documentId,
        'qr_url' => $qrUrl,
        'embed_code' => $plainEmbedToken,
        'qr_token' => $plainQrToken
    ]);
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
