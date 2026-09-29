<?php
declare(strict_types=1);

/**
 * Мінімальна реалізація Web Push (VAPID, RFC 8291 aes128gcm) без залежностей.
 * Працює на PHP з openssl. Ключі генеруються в адмінці (Налаштування → Push).
 */
class WebPush
{
    public static function ensureKeys(): array
    {
        $pub = Settings::get('vapid_public');
        $priv = Settings::get('vapid_private');
        if (!$pub || !$priv) {
            $res = self::newKey();
            if ($res === false) return ['', ''];
            $det = openssl_pkey_get_details($res);
            $x = $det['ec']['x']; $y = $det['ec']['y']; $d = $det['ec']['d'];
            $pub = self::b64($det['ec']['x'] !== '' ? "\x04" . $x . $y : '');
            $priv = self::b64($d);
            Settings::set('vapid_public', $pub);
            Settings::set('vapid_private', $priv);
        }
        return [$pub, $priv];
    }

    /**
     * Ключ P-256 для VAPID.
     *
     * На Linux (де стоїть бойовий сайт) працює перший же виклик. На Windows із
     * XAMPP openssl не знаходить свій openssl.cnf і падає з «configuration file
     * routines::no such file» — а назовні це виглядало просто як «пуші не
     * працюють», без жодної підказки. Тому за невдачі пробуємо явно вказати
     * конфіг із типових місць XAMPP.
     */
    private static function newKey()
    {
        $args = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
        $res = @openssl_pkey_new($args);
        if ($res !== false) return $res;

        while (openssl_error_string()) {}   // черга помилок не має текти в наступні виклики
        foreach ([
            getenv('OPENSSL_CONF') ?: null,
            'C:/xampp/apache/conf/openssl.cnf',
            'C:/xampp/php/extras/ssl/openssl.cnf',
        ] as $cnf) {
            if (!$cnf || !is_file($cnf)) continue;
            $res = @openssl_pkey_new($args + ['config' => $cnf]);
            if ($res !== false) return $res;
            while (openssl_error_string()) {}
        }
        return false;
    }

    public static function sendToAll(array $subs, array $payload): void
    {
        foreach ($subs as $sub) {
            try { self::sendOne($sub, json_encode($payload, JSON_UNESCAPED_UNICODE)); }
            catch (Throwable $e) { Notify::log('push: ' . $e->getMessage()); }
        }
    }

    private static function sendOne(array $sub, string $payload): void
    {
        [$vapidPub, $vapidPriv] = self::ensureKeys();
        if (!$vapidPub || !$vapidPriv) return;

        $endpoint = $sub['endpoint'];
        $userPub = self::b64d($sub['p256dh']);
        $userAuth = self::b64d($sub['auth']);

        // ECDH: ефемерна пара
        $eph = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ephDet = openssl_pkey_get_details($eph);
        $ephPub = "\x04" . $ephDet['ec']['x'] . $ephDet['ec']['y'];

        $shared = self::ecdh($eph, $userPub);
        if ($shared === null) return;

        // HKDF за RFC 8291
        $prkKey = hash_hmac('sha256', $shared, $userAuth, true);
        $keyInfo = "WebPush: info\x00" . $userPub . $ephPub;
        $ikm = hash_hmac('sha256', $keyInfo . "\x01", $prkKey, true);

        $salt = random_bytes(16);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\x00\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\x00\x01", $prk, true), 0, 12);

        $padded = $payload . "\x02";
        $tag = '';
        $cipher = openssl_encrypt($padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        $body = $salt . pack('N', 4096) . chr(strlen($ephPub)) . $ephPub . $cipher . $tag;

        $jwt = self::vapidJwt($endpoint, $vapidPriv);
        $headers = [
            'TTL: 86400',
            'Content-Encoding: aes128gcm',
            'Content-Type: application/octet-stream',
            'Authorization: vapid t=' . $jwt . ', k=' . $vapidPub,
        ];
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 404 || $code === 410) {
            DB::delete('push_subscriptions', 'id = ?', [$sub['id']]);
        }
    }

    private static function ecdh($privKey, string $peerPubRaw): ?string
    {
        // Побудова PEM для публічного ключа peer (P-256 uncompressed point)
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $peerPubRaw;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $peer = openssl_pkey_get_public($pem);
        if ($peer === false) return null;
        $secret = openssl_pkey_derive($peer, $privKey, 32);
        return $secret === false ? null : $secret;
    }

    private static function vapidJwt(string $endpoint, string $privB64): string
    {
        $aud = parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST);
        $header = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64(json_encode([
            'aud' => $aud, 'exp' => time() + 43200,
            // Контакт для push-сервісу: та сама адреса, з якої йдуть листи. Окремого
            // 'admin@localhost' тут більше немає — на нього все одно ніхто не
            // читає, а Google цим полем повідомляє, що з нашими пушами не так.
            'sub' => 'mailto:' . Notify::mailFrom(),
        ]));
        $unsigned = $header . '.' . $claims;
        $d = self::b64d($privB64);
        $pem = self::ecPrivatePem($d);
        $key = openssl_pkey_get_private($pem);
        openssl_sign($unsigned, $sigDer, $key, OPENSSL_ALGO_SHA256);
        // DER → raw R||S
        $sig = self::derToRs($sigDer);
        return $unsigned . '.' . self::b64($sig);
    }

    private static function ecPrivatePem(string $d): string
    {
        $der = hex2bin('30310201010420') . str_pad($d, 32, "\x00", STR_PAD_LEFT)
             . hex2bin('a00a06082a8648ce3d030107');
        return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
    }

    private static function derToRs(string $der): string
    {
        $offset = 2;
        if (ord($der[1]) > 0x80) $offset += ord($der[1]) - 0x80;
        $rLen = ord($der[$offset + 1]);
        $r = substr($der, $offset + 2, $rLen);
        $offset += 2 + $rLen;
        $sLen = ord($der[$offset + 1]);
        $s = substr($der, $offset + 2, $sLen);
        $r = ltrim($r, "\x00"); $s = ltrim($s, "\x00");
        return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
    }

    public static function b64(string $data): string
    { return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); }

    public static function b64d(string $data): string
    { return base64_decode(strtr($data, '-_', '+/')); }
}
