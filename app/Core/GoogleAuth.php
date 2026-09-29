<?php
declare(strict_types=1);

/** Google OAuth 2.0 (без залежностей) */
class GoogleAuth
{
    public static function configured(): bool
    {
        return self::clientId() !== '' && self::clientSecret() !== '';
    }

    public static function clientId(): string
    { return (string)(Settings::get('google_client_id') ?: cfg('google.client_id', '')); }

    public static function clientSecret(): string
    { return (string)(Settings::get('google_client_secret') ?: cfg('google.client_secret', '')); }

    public static function redirectUri(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . base_url('/auth/google/callback');
    }

    public static function authUrl(): string
    {
        $state = bin2hex(random_bytes(12));
        $_SESSION['oauth_state'] = $state;
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => self::clientId(),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
        ]);
    }

    public static function handleCallback(): ?array
    {
        if (($_GET['state'] ?? '') !== ($_SESSION['oauth_state'] ?? null)) return null;
        unset($_SESSION['oauth_state']);
        $code = $_GET['code'] ?? '';
        if (!$code) return null;

        $token = self::post('https://oauth2.googleapis.com/token', [
            'code' => $code, 'client_id' => self::clientId(), 'client_secret' => self::clientSecret(),
            'redirect_uri' => self::redirectUri(), 'grant_type' => 'authorization_code',
        ]);
        if (empty($token['access_token'])) return null;

        $ch = curl_init('https://openidconnect.googleapis.com/v1/userinfo');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token['access_token']],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $profile = json_decode((string)$resp, true);
        if (!is_array($profile) || empty($profile['sub']) || empty($profile['email'])) return null;
        // непідтверджений email не можна довіряти: інакше через нього можна
        // «підʼєднатись» до чужого акаунта, який має ту саму адресу
        if (array_key_exists('email_verified', $profile)
            && !filter_var($profile['email_verified'], FILTER_VALIDATE_BOOLEAN)) return null;
        return $profile;
    }

    private static function post(string $url, array $data): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $json = json_decode((string)$resp, true);
        return is_array($json) ? $json : [];
    }
}
