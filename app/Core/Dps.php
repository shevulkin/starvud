<?php
declare(strict_types=1);

/**
 * Звʼязок із фіскальним сервером ДПС: /doc (документи), /cmd (команди).
 *
 * Сервер приймає лише вже підписане КЕП касира — підпис робить браузер
 * (assets/js/dps-sign.js), ключ і пароль на сайт не потрапляють ніколи. Наш
 * сервер лише пересилає підписане й розбирає відповідь: браузер сам до
 * fs.tax.gov.ua не дійде (CORS), а відповідь нам однаково потрібна тут.
 *
 * Квитанції й відповіді на підписані команди сервер теж підписує. Перевіряти
 * його підпис ми не беремось (для цього потрібна та сама бібліотека ДСТУ, що
 * в браузері), — відповідь приходить до нас TLS-зʼєднанням просто від
 * fs.tax.gov.ua, і довіра тут та сама, що до будь-якого HTTPS-API. Нам треба
 * лише дістати вміст із конверта CMS — це робить content().
 */
class Dps
{
    /** Підмінний транспорт для тестів: fn(string $path, string $body, string $type): array */
    public static $transport = null;

    public static function base(): string
    {
        $url = trim((string)Settings::get('dps_fs_url', ''));
        return rtrim($url !== '' ? $url : DpsDoc::FS_URL, '/');
    }

    /**
     * POST на фіскальний сервер.
     *
     * @param string $path 'doc' | 'cmd'
     * @return array{status:int,body:string,error:string} status 0 — відповіді не було
     */
    public static function post(string $path, string $body, bool $signed): array
    {
        $type = $signed ? 'application/octet-stream' : 'application/json; charset=UTF-8';
        if (is_callable(self::$transport)) return (self::$transport)($path, $body, $type);

        $ch = curl_init(self::base() . '/' . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: ' . $type, 'Expect:'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            // Сервер при навантаженні відповідає повільно, а обірваний запит
            // означає «невідомо, чи зареєстровано» — краще почекати
            CURLOPT_TIMEOUT => 40,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = $resp === false ? curl_error($ch) : '';
        curl_close($ch);
        if ($resp === false) {
            error_log('DPS ' . $path . ': ' . $err);
            return ['status' => 0, 'body' => '', 'error' => $err ?: 'немає звʼязку'];
        }
        return ['status' => $status, 'body' => (string)$resp, 'error' => ''];
    }

    /** Непідписана команда (стан сервера, схеми): JSON → масив */
    public static function ping(): array
    {
        $r = self::post('cmd', DpsDoc::command('ServerState'), false);
        $j = json_decode($r['body'], true);
        return ['ok' => $r['status'] === 200 && is_array($j) && isset($j['Timestamp']),
                'time' => (string)($j['Timestamp'] ?? ''), 'error' => $r['error'] ?: ($r['status'] !== 200 ? DpsDoc::errorText($r['body'])['text'] : '')];
    }

    // ──────────────────────────────────────────────────────────────── CMS

    /**
     * Вміст підписаного повідомлення (CMS SignedData → eContent).
     * Не CMS (звичайний JSON чи XML) — повертаємо як є.
     */
    public static function content(string $der): string
    {
        if ($der === '' || $der[0] !== "\x30") return $der;
        try {
            $pos = 0;
            $ci = self::tlv($der, $pos);                         // ContentInfo
            $kids = self::children($ci['value'], $ci['constructed']);
            if (count($kids) < 2) return $der;
            $sd = self::children($kids[1]['value'], true);       // [0] EXPLICIT
            if (!$sd) return $der;
            $sdKids = self::children($sd[0]['value'], true);     // SignedData
            foreach ($sdKids as $k) {
                // encapContentInfo — перша SEQUENCE після версії й алгоритмів
                if ($k['tag'] !== 0x30) continue;
                $eci = self::children($k['value'], true);
                if (count($eci) >= 2 && $eci[0]['tag'] === 0x06 && $eci[1]['tag'] === 0xA0) {
                    $oct = self::children($eci[1]['value'], true);
                    return $oct ? self::octets($oct[0]) : '';
                }
            }
        } catch (Throwable $e) {
            return $der;
        }
        return $der;
    }

    /** Байти OCTET STRING — і простого, і складеного шматками (BER) */
    private static function octets(array $t): string
    {
        if (!$t['constructed']) return $t['value'];
        $out = '';
        foreach (self::children($t['value'], true) as $c) $out .= self::octets($c);
        return $out;
    }

    /** @return array<int,array{tag:int,constructed:bool,value:string}> */
    private static function children(string $data, bool $constructed): array
    {
        if (!$constructed) return [];
        $out = []; $pos = 0; $len = strlen($data);
        while ($pos < $len) {
            if ($pos + 1 < $len && $data[$pos] === "\0" && $data[$pos + 1] === "\0") break; // кінець невизначеної довжини
            $out[] = self::tlv($data, $pos);
        }
        return $out;
    }

    /** Один елемент DER/BER з позиції $pos (зсуває $pos за нього) */
    private static function tlv(string $d, int &$pos): array
    {
        $n = strlen($d);
        if ($pos + 2 > $n) throw new RuntimeException('обірваний ASN.1');
        $tag = ord($d[$pos++]);
        $constructed = (bool)($tag & 0x20);
        if (($tag & 0x1F) === 0x1F) {                  // довгий тег — пропускаємо його байти
            while ($pos < $n && (ord($d[$pos++]) & 0x80));
        }
        $lb = ord($d[$pos++]);
        if ($lb === 0x80) {                            // невизначена довжина (BER)
            $start = $pos; $depth = 0;
            // шукаємо відповідний кінець, розбираючи вкладені елементи
            $inner = substr($d, $start);
            $p = 0; $m = strlen($inner);
            while ($p < $m) {
                if ($p + 1 < $m && $inner[$p] === "\0" && $inner[$p + 1] === "\0") { $p += 2; break; }
                self::tlv($inner, $p);
            }
            $pos = $start + $p;
            return ['tag' => $tag, 'constructed' => $constructed, 'value' => substr($inner, 0, $p - 2)];
        }
        $len = $lb;
        if ($lb & 0x80) {
            $cnt = $lb & 0x7F;
            if ($cnt > 4 || $pos + $cnt > $n) throw new RuntimeException('завелика довжина ASN.1');
            $len = 0;
            for ($i = 0; $i < $cnt; $i++) $len = ($len << 8) | ord($d[$pos++]);
        }
        if ($pos + $len > $n) throw new RuntimeException('обірваний ASN.1');
        $value = substr($d, $pos, $len);
        $pos += $len;
        return ['tag' => $tag, 'constructed' => $constructed, 'value' => $value];
    }

    // ───────────────────────────────────────────────────── проксі до ЦСК

    /**
     * Хости, до яких пускає проксі бібліотеки підпису: сервери ЦСК зі списку
     * CAs.json і кореневі органи (для перевірки статусу сертифіката).
     * Будь-яка інша адреса — відмова: інакше маршрут став би відкритим
     * проксі, через який можна стукати куди завгодно від імені нашого сервера.
     */
    public static function caHosts(): array
    {
        static $hosts = null;
        if ($hosts !== null) return $hosts;
        $hosts = ['czo.gov.ua' => true, 'zc.bank.gov.ua' => true];
        $file = BOFU_ROOT . '/assets/vendor/eusign/CAs.json';
        $cas = is_file($file) ? json_decode(preg_replace('/^\xEF\xBB\xBF/', '', (string)file_get_contents($file)), true) : [];
        foreach ((array)$cas as $ca) {
            foreach (['address', 'cmpAddress', 'tspAddress', 'ocspAccessPointAddress'] as $k) {
                $h = self::caHost((string)($ca[$k] ?? ''));
                if ($h !== '') $hosts[$h] = true;
            }
        }
        return $hosts;
    }

    /** Хост із адреси бібліотеки ('ca.tax.gov.ua/services/ocsp/'); '' — адреса непридатна */
    public static function caHost(string $address): string
    {
        $address = trim($address);
        if ($address === '') return '';
        $url = str_contains($address, '://') ? $address : 'http://' . $address;
        $p = parse_url($url);
        if (!$p || !in_array(strtolower((string)($p['scheme'] ?? '')), ['http', 'https'], true)) return '';
        if (isset($p['user']) || isset($p['pass'])) return '';
        return strtolower((string)($p['host'] ?? ''));
    }
}
