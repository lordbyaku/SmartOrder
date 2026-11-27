<?php
/**
 * Native PHP 8.2 Web Push Sender — Zero Composer / Zero vendor.
 *  - Enkripsi payload: RFC 8291 (Message Encryption for Web Push) + RFC 8188 (aes128gcm)
 *  - Autentikasi server: RFC 8292 (VAPID) JWT ES256
 * Hanya memakai ekstensi bawaan: openssl, curl, hash.
 */
declare(strict_types=1);

final class WebPush
{
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    private string $publicKey;   // base64url, titik P-256 uncompressed 65 byte
    private string $privateKeyPem;
    private string $subject;     // mailto:... atau https://...
    /** @var array<string,string> cache JWT per audience */
    private array $jwtCache = [];

    public function __construct(string $publicKeyB64u, string $privateKeyPem, string $subject)
    {
        $this->publicKey = $publicKeyB64u;
        $this->privateKeyPem = $privateKeyPem;
        $this->subject = $subject;
        if (strlen(self::b64uDecode($publicKeyB64u)) !== 65) {
            throw new RuntimeException('VAPID public key tidak valid.');
        }
    }

    /* ---------------------------- util ------------------------------ */

    public static function b64uEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $str): string
    {
        $str = strtr(trim($str), '-_', '+/');
        $pad = strlen($str) % 4;
        if ($pad) {
            $str .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode($str, true);
        return $out === false ? '' : $out;
    }

    /** Konversi public key mentah (0x04||X||Y) menjadi PEM SPKI. */
    public static function rawPublicToPem(string $raw): string
    {
        $der = hex2bin(self::P256_SPKI_PREFIX) . $raw;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** Ambil public key mentah 65 byte dari resource/objek key EC. */
    public static function rawPublicFromKey($key): string
    {
        $d = openssl_pkey_get_details($key);
        if (!$d || !isset($d['ec']['x'], $d['ec']['y'])) {
            throw new RuntimeException('Gagal membaca detail kunci EC.');
        }
        return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    }

    /** Opsi openssl (Windows/XAMPP kadang butuh path openssl.cnf). */
    public static function opensslOptions(): array
    {
        $opts = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
        $cnf = getenv('OPENSSL_CONF');
        if (!$cnf) {
            foreach (['/etc/ssl/openssl.cnf', '/etc/pki/tls/openssl.cnf', '/usr/lib/ssl/openssl.cnf',
                      'C:/xampp/php/extras/ssl/openssl.cnf', 'D:/XAMPP/php/extras/ssl/openssl.cnf',
                      'C:/xampp/apache/conf/openssl.cnf', 'D:/XAMPP/apache/conf/openssl.cnf'] as $c) {
                if (@is_file($c)) {
                    $cnf = $c;
                    break;
                }
            }
        }
        if ($cnf) {
            $opts['config'] = $cnf;
        }
        return $opts;
    }

    /** Generate pasangan kunci VAPID baru: ['publicKey' => b64u, 'privateKeyPem' => PEM]. */
    public static function generateVapidKeys(): array
    {
        $opts = self::opensslOptions();
        $key = openssl_pkey_new($opts);
        if ($key === false) {
            throw new RuntimeException('openssl_pkey_new gagal: ' . (openssl_error_string() ?: 'unknown'));
        }
        $pem = '';
        if (!openssl_pkey_export($key, $pem, null, $opts)) {
            throw new RuntimeException('openssl_pkey_export gagal: ' . (openssl_error_string() ?: 'unknown'));
        }
        return ['publicKey' => self::b64uEncode(self::rawPublicFromKey($key)), 'privateKeyPem' => $pem];
    }

    /** DER ECDSA signature -> raw r||s (64 byte) untuk JWT ES256. */
    public static function derToRaw(string $der): string
    {
        $pos = 0;
        if (ord($der[$pos++]) !== 0x30) {
            throw new RuntimeException('Signature DER tidak valid.');
        }
        $len = ord($der[$pos++]);
        if ($len & 0x80) {
            $pos += $len & 0x7f;
        }
        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            if (ord($der[$pos++]) !== 0x02) {
                throw new RuntimeException('Signature DER tidak valid.');
            }
            $l = ord($der[$pos++]);
            $int = substr($der, $pos, $l);
            $pos += $l;
            $int = ltrim($int, "\0");
            $parts[] = str_pad($int, 32, "\0", STR_PAD_LEFT);
        }
        return $parts[0] . $parts[1];
    }

    /* ------------------------- enkripsi ----------------------------- */

    /**
     * Enkripsi payload sesuai RFC 8291 (aes128gcm).
     * @param string $uaPublic  p256dh subscriber (raw 65 byte)
     * @param string $authSecret auth subscriber (raw 16 byte)
     */
    public static function encrypt(string $payload, string $uaPublic, string $authSecret, ?string $salt = null, $localKey = null): string
    {
        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04") {
            throw new InvalidArgumentException('p256dh tidak valid.');
        }
        if (strlen($authSecret) < 16) {
            throw new InvalidArgumentException('auth secret tidak valid.');
        }
        $localKey = $localKey ?? openssl_pkey_new(self::opensslOptions());
        if ($localKey === false) {
            throw new RuntimeException('Gagal membuat kunci ECDH: ' . (openssl_error_string() ?: 'unknown'));
        }
        $asPublic = self::rawPublicFromKey($localKey);
        $peer = openssl_pkey_get_public(self::rawPublicToPem($uaPublic));
        if ($peer === false) {
            throw new RuntimeException('Public key subscriber tidak dapat dibaca.');
        }
        $shared = openssl_pkey_derive($peer, $localKey);
        if ($shared === false || $shared === '') {
            throw new RuntimeException('ECDH gagal: ' . (openssl_error_string() ?: 'unknown'));
        }
        $shared = str_pad($shared, 32, "\0", STR_PAD_LEFT);

        $salt = $salt ?? random_bytes(16);
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        $tag = '';
        $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('AES-GCM gagal.');
        }
        $recordSize = 4096;
        return $salt . pack('N', $recordSize) . chr(65) . $asPublic . $cipher . $tag;
    }

    /* --------------------------- VAPID ------------------------------ */

    private function vapidAuthorization(string $endpoint): string
    {
        $p = parse_url($endpoint);
        $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        if (!isset($this->jwtCache[$aud])) {
            $header = self::b64uEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
            $claims = self::b64uEncode(json_encode([
                'aud' => $aud,
                'exp' => time() + 12 * 3600,
                'sub' => $this->subject,
            ], JSON_UNESCAPED_SLASHES));
            $input = $header . '.' . $claims;
            $priv = openssl_pkey_get_private($this->privateKeyPem);
            if ($priv === false) {
                throw new RuntimeException('VAPID private key tidak valid.');
            }
            $der = '';
            if (!openssl_sign($input, $der, $priv, OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Gagal menandatangani VAPID JWT.');
            }
            $this->jwtCache[$aud] = $input . '.' . self::b64uEncode(self::derToRaw($der));
        }
        return 'vapid t=' . $this->jwtCache[$aud] . ', k=' . $this->publicKey;
    }

    /* --------------------------- kirim ------------------------------ */

    /**
     * Kirim notifikasi ke banyak subscription paralel (curl_multi).
     * @param array<int,array{id:int|string,endpoint:string,p256dh:string,auth:string}> $subs
     * @return array<string|int,int> map id => HTTP status (0 = gagal koneksi / enkripsi)
     */
    public function sendMany(array $subs, string $payload, int $ttl = 3600, string $urgency = 'high'): array
    {
        $results = [];
        if (!$subs) {
            return $results;
        }
        $mh = curl_multi_init();
        $handles = [];
        foreach ($subs as $sub) {
            $id = $sub['id'];
            try {
                $endpoint = (string) $sub['endpoint'];
                if (!preg_match('#^https://#i', $endpoint)) {
                    throw new InvalidArgumentException('Endpoint bukan https.');
                }
                $body = self::encrypt($payload, self::b64uDecode($sub['p256dh']), self::b64uDecode($sub['auth']));
                $ch = curl_init($endpoint);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $body,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/octet-stream',
                        'Content-Encoding: aes128gcm',
                        'Content-Length: ' . strlen($body),
                        'TTL: ' . $ttl,
                        'Urgency: ' . $urgency,
                        'Authorization: ' . $this->vapidAuthorization($endpoint),
                    ],
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$id] = $ch;
            } catch (Throwable $e) {
                error_log('[GAHC WebPush] encrypt/prepare gagal untuk sub ' . $id . ': ' . $e->getMessage());
                $results[$id] = 0;
            }
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $transferErrors = [];
        while ($info = curl_multi_info_read($mh)) {
            if ($info['result'] !== CURLE_OK) {
                $transferErrors[spl_object_id($info['handle'])] = curl_strerror($info['result']);
            }
        }

        foreach ($handles as $id => $ch) {
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($code < 200 || $code >= 300) {
                $err = curl_error($ch) ?: ($transferErrors[spl_object_id($ch)] ?? '');
                error_log('[GAHC WebPush] sub ' . $id . ' HTTP ' . $code . ' ' . $err . ' ' . substr((string) curl_multi_getcontent($ch), 0, 200));
            }
            $results[$id] = $code;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $results;
    }
}

/**
 * Helper aplikasi: kirim push ke perangkat staf yang memantau outlet tertentu.
 * Endpoint kedaluwarsa (404/410) otomatis dihapus.
 */
function push_notify_outlet(PDO $pdo, string $outlet, array $message): array
{
    $stmt = $pdo->prepare("SELECT id, endpoint, p256dh_key AS p256dh, auth_token AS auth
                           FROM staff_push_subscriptions
                           WHERE outlet_filter = 'ALL' OR outlet_filter = ?");
    $stmt->execute([$outlet]);
    return push_send_to_subscriptions($pdo, $stmt->fetchAll(), $message);
}

/** Baca config/vapid.php. Return null jika belum tersedia. */
function vapid_config(): ?array
{
    $vapidFile = __DIR__ . '/vapid.php';
    if (!is_file($vapidFile)) {
        return null;
    }
    $vapid = require $vapidFile;
    if (!is_array($vapid) || empty($vapid['publicKey']) || empty($vapid['privateKeyPem'])) {
        return null;
    }
    return $vapid;
}

/** @param array<int,array{id:int,endpoint:string,p256dh:string,auth:string}> $subs */
function push_send_to_subscriptions(PDO $pdo, array $subs, array $message): array
{
    if (!$subs) {
        return ['sent' => 0, 'total' => 0];
    }
    if (!extension_loaded('openssl') || !extension_loaded('curl')) {
        return ['sent' => 0, 'skipped' => 'ekstensi openssl/curl tidak aktif'];
    }
    $vapid = vapid_config();
    if ($vapid === null) {
        return ['sent' => 0, 'skipped' => 'config/vapid.php belum ada'];
    }
    $subject = (string) ($vapid['subject'] ?? '');
    if ($subject === '') {
        $subject = app_base_url();
    }
    $wp = new WebPush($vapid['publicKey'], $vapid['privateKeyPem'], $subject);
    $results = $wp->sendMany($subs, json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $gone = [];
    $sent = 0;
    foreach ($results as $id => $code) {
        if ($code === 404 || $code === 410) {
            $gone[] = (int) $id;
        } elseif ($code >= 200 && $code < 300) {
            $sent++;
        }
    }
    if ($gone) {
        $in = implode(',', array_fill(0, count($gone), '?'));
        $pdo->prepare("DELETE FROM staff_push_subscriptions WHERE id IN ($in)")->execute($gone);
    }
    return ['sent' => $sent, 'removed' => count($gone), 'total' => count($subs), 'results' => $results];
}
