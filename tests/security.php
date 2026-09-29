<?php
/**
 * Заголовки безпеки й екранування.  Запуск: php bin/cli.php test
 *
 * Ці перевірки існують не тому, що політику важко написати, — а тому, що її
 * легко ослабити випадково. Директиву додають, щоб «запрацювала карта», і
 * разом із доменом карти в неї заїжджає щось іще. Тест фіксує ті обмеження,
 * які ми свідомо поставили: якщо котресь зникне, це буде видно тут, а не через
 * пів року в чужому звіті.
 *
 * CSP перевіряємо рядком, а не браузером: браузерна половина живе в самому
 * браузері, і сюди її не затягнути. Тому доводимо те, що можна довести, —
 * що директиви на місці й що вони не «дозволити все».
 */
declare(strict_types=1);

final class SecurityTest
{
    private int $pass = 0;
    private int $fail = 0;

    public function run(): int
    {
        $this->testCsp();
        $this->testCspNotWideOpen();
        $this->testExternalImages();
        $this->testEscaping();
        $this->testJsonInScript();
        $this->testHstsGuard();

        echo "\n" . ($this->fail === 0
            ? "УСЕ ДОБРЕ: {$this->pass} перевірок\n"
            : "ПРОВАЛЕНО: {$this->fail} з " . ($this->pass + $this->fail) . "\n");
        return $this->fail === 0 ? 0 : 1;
    }

    private function ok(bool $cond, string $what): void
    {
        if ($cond) { $this->pass++; echo "  ✓ $what\n"; }
        else { $this->fail++; echo "  ✗ $what\n"; }
    }

    /** Політику читаємо тим самим кодом, що й віддає її браузеру */
    private function csp(): string
    {
        $m = new ReflectionMethod(Security::class, 'csp');
        $m->setAccessible(true);
        return (string)$m->invoke(null);
    }

    private function testCsp(): void
    {
        echo "== директиви на місці ==\n";
        $csp = $this->csp();
        $must = [
            "default-src 'self'" => 'усе, що не названо окремо, — лише з нашого домену',
            "object-src 'none'" => 'плагіни вимкнені',
            "base-uri 'self'" => '<base> не підмінить шляхи сторінки на чужий домен',
            "form-action 'self'" => 'вставлена форма не відправить дані назовні',
            "frame-ancestors 'none'" => 'сайт не вкласти у фрейм (клікджекінг)',
            "frame-src 'none'" => 'на сторінці не буде чужих фреймів',
        ];
        foreach ($must as $needle => $why) {
            $this->ok(str_contains($csp, $needle), $why);
        }
    }

    private function testCspNotWideOpen(): void
    {
        echo "== політика не «дозволити все» ==\n";
        $csp = $this->csp();
        // Найпоширеніший спосіб «полагодити» CSP — дописати зірочку. Після цього
        // політика лишається в заголовку й виглядає як захист, не будучи ним.
        $this->ok(!str_contains($csp, "default-src *"), 'default-src не зірочка');
        $this->ok(!preg_match("~script-src[^;]*\*~", $csp), 'script-src не пускає будь-який домен');
        $this->ok(!preg_match("~connect-src[^;]*\*(?!\.ggpht)~", $csp), 'connect-src не пускає будь-який домен');
        // 'unsafe-eval' дозволяє виконати рядок як код — саме те, чим
        // користуються після вдалої вставки
        $this->ok(!str_contains($csp, "'unsafe-eval'"), "'unsafe-eval' не дозволений");
        // Дозволені чужі домени перелічені поіменно й лише https
        preg_match_all('~https?://[^\s;]+~', $csp, $m);
        $this->ok(!array_filter($m[0], fn($u) => str_starts_with($u, 'http://')),
            'жодного дозволу по незахищеному http');
    }

    /**
     * Чужі картинки, які сайт справді показує, мають бути дозволені.
     *
     * Перевіряємо не список, переписаний руками, а сам код: домен беремо з
     * YouTube.php, тобто звідти, де він і будується. Якщо його колись змінять
     * на інший CDN, тест впаде — а не прев'ю на головній, мовчки й у продакшні,
     * як це вже сталося одного разу.
     */
    private function testExternalImages(): void
    {
        echo "== чужі картинки дозволені ==\n";
        $src = file_get_contents(BOFU_ROOT . '/app/Core/YouTube.php');
        if (!preg_match("~'(https://[a-z0-9.-]+)/vi/~i", (string)$src, $m)) {
            $this->ok(false, 'у YouTube.php знайдено домен прев\'ю');
            return;
        }
        $host = $m[1];
        $csp = $this->csp();
        preg_match('~img-src([^;]*)~', $csp, $img);
        $this->ok(str_contains($img[1] ?? '', $host), "img-src дозволяє $host (прев'ю відео)");
    }

    private function testEscaping(): void
    {
        echo "== екранування у HTML ==\n";
        $this->ok(e('<script>') === '&lt;script&gt;', 'теги не проходять');
        $this->ok(e('" onerror="x') === '&quot; onerror=&quot;x', 'лапки не розривають атрибут');
        $this->ok(e("' onload='x") === '&#039; onload=&#039;x', 'одинарні лапки теж');
        $this->ok(e(null) === '', 'null не стає рядком «null»');
    }

    /** Дані всередині <script> — окремий випадок: там HTML-екранування не діє */
    private function testJsonInScript(): void
    {
        echo "== дані всередині <script> ==\n";
        $evil = ['name' => '</script><script>alert(1)</script>'];
        $out = json_js($evil);
        $this->ok(!str_contains($out, '</script>'), 'закривальний тег не виживає');
        $this->ok(!str_contains($out, '<'), 'кутових дужок у виводі немає взагалі');
        $this->ok(json_decode($out, true) === $evil, 'дані при цьому не зіпсовані');
    }

    /**
     * HSTS не має вмикатися сам. Заголовок памʼятається браузером місяцями й
     * діє на весь домен — увімкнений випадково, він кладе сусідній сайт, який
     * ще не на HTTPS, і швидко це не відкотити.
     */
    private function testHstsGuard(): void
    {
        echo "== HSTS ==\n";
        $this->ok(cfg('hsts') === false, 'за замовчуванням вимкнений');
        // Довіра до заголовка проксі — теж рішення, а не типова поведінка:
        // інакше будь-хто оголошує своє http-зʼєднання захищеним
        $this->ok(cfg('trust_proxy') === false, 'заголовку проксі за замовчуванням не віримо');
    }
}

return (new SecurityTest())->run();
