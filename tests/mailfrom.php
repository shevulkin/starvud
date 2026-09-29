<?php
/**
 * Адреса, з якої сайт пише людям.  Запуск: php bin/cli.php test
 *
 * Перевіряти «чи дійшов лист» тут неможливо — пошта не наша. Зате можна
 * перевірити те, через що листи не доходять найчастіше, і ціна помилки в чому
 * висока:
 *
 *   1) Коди входу мають окрему скриньку. Якщо вона задана, лист із кодом іде
 *      саме з неї, а решта листів — із загальної. Це не косметика: скарга
 *      «спам» на лист про акцію просаджує репутацію адреси, і коли адреса одна,
 *      разом із нею перестають доходити коди — тобто люди не можуть увійти.
 *
 *   2) Порожні поля нічого не ламають: усе йде однією адресою, як до появи
 *      другої. Налаштування, яке треба обовʼязково заповнити, щоб сайт лишався
 *      робочим, — це не налаштування, а поламаний сайт із домашнім завданням.
 *
 *   3) В адресу відправника не можна вписати заголовок листа. Поле редагує
 *      адмін, але «своя людина в адмінці» — не гарантія: перенос рядка в ньому
 *      дописав би до КОЖНОГО сповіщення чужий Bcc, і помітили б це нескоро.
 *
 * Набір підміняє налаштування пошти й наприкінці повертає їх точно як були.
 */
declare(strict_types=1);

final class MailFromTest
{
    private const KEYS = ['mail_from', 'mail_from_auth', 'mail_reply_to', 'bot_site_url'];

    private int $pass = 0;
    private int $fail = 0;
    private array $saved = [];

    public function run(): int
    {
        foreach (self::KEYS as $k) $this->saved[$k] = Settings::get($k, null);
        try {
            $this->testDefaults();
            $this->testOneAddressForEverything();
            $this->testSeparateAuthAddress();
            $this->testReplyTo();
            $this->testNoHeaderInjection();
            $this->testHost();
        } finally {
            $this->tearDown();
        }
        echo "\n" . ($this->fail === 0
            ? "УСЕ ДОБРЕ: {$this->pass} перевірок\n"
            : "ПРОВАЛЕНО: {$this->fail} з " . ($this->pass + $this->fail) . "\n");
        return $this->fail === 0 ? 0 : 1;
    }

    private function tearDown(): void
    {
        // Порожній рядок і відсутній запис для Settings::get — одне й те саме,
        // тож повертати «як було» можна простим записом порожнього
        foreach (self::KEYS as $k) Settings::set($k, (string)($this->saved[$k] ?? ''));
    }

    private function ok(string $what, bool $cond): void
    {
        if ($cond) { $this->pass++; echo "  ok   $what\n"; }
        else { $this->fail++; echo "  FAIL $what\n"; }
    }

    private function group(string $name): void { echo "\n== $name ==\n"; }

    /** Налаштувати пошту: null — прибрати значення зовсім */
    private function mail(?string $from, ?string $auth = null, ?string $reply = null): void
    {
        Settings::set('mail_from', (string)$from);
        Settings::set('mail_from_auth', (string)$auth);
        Settings::set('mail_reply_to', (string)$reply);
    }

    private function testDefaults(): void
    {
        $this->group('Нічого не заповнено');
        Settings::set('bot_site_url', 'https://bofu.ua');
        $this->mail(null);
        $host = Notify::mailHost();
        $this->ok('сповіщення йдуть від shop@домен', Notify::mailFrom('order_new') === 'shop@' . $host);
        $this->ok('коди входу — від login@домен', Notify::mailFrom('auth_code') === 'login@' . $host);
        // noreply@ немає ніде свідомо: на листи про замовлення відповідають, і
        // відповідь у noreply зникає — власник не дізнається, що йому писали
        $this->ok('жодного noreply@ за замовчуванням',
            !str_contains(Notify::mailFrom('order_new') . Notify::mailFrom('auth_code'), 'noreply'));
        $this->ok('без Reply-To: ставити нікуди', Notify::mailReplyTo() === '');
    }

    private function testOneAddressForEverything(): void
    {
        $this->group('Одна адреса на все');
        $this->mail('shop@bofu.ua');
        $this->ok('сповіщення — з неї', Notify::mailFrom('order_new') === 'shop@bofu.ua');
        $this->ok('і коди входу теж', Notify::mailFrom('auth_code') === 'shop@bofu.ua');
        $this->ok('Reply-To дорівнює From (заголовка не буде)', Notify::mailReplyTo() === 'shop@bofu.ua');
    }

    private function testSeparateAuthAddress(): void
    {
        $this->group('Окрема скринька для кодів');
        $this->mail('shop@bofu.ua', 'login@bofu.ua');
        $this->ok('код іде зі скриньки входу', Notify::mailFrom('auth_code') === 'login@bofu.ua');
        $this->ok('замовлення — із загальної', Notify::mailFrom('order_new') === 'shop@bofu.ua');
        $this->ok('подія не вказана — теж загальна', Notify::mailFrom() === 'shop@bofu.ua');

        // Порожня загальна при заповненій «вхідній» — випадок безглуздий, але
        // трапляється при заповненні форми згори вниз; сайт має лишитись робочим
        $this->mail(null, 'login@bofu.ua');
        $this->ok('коди йдуть, навіть якщо загальна порожня', Notify::mailFrom('auth_code') === 'login@bofu.ua');
        $this->ok('решта листів бере замовчування', Notify::mailFrom('order_new') === 'shop@' . Notify::mailHost());
    }

    private function testReplyTo(): void
    {
        $this->group('Куди піде відповідь');
        $this->mail('shop@bofu.ua', 'login@bofu.ua', 'hello@bofu.ua');
        $this->ok('вписана адреса старша за загальну', Notify::mailReplyTo() === 'hello@bofu.ua');
        $this->mail('shop@bofu.ua', 'login@bofu.ua');
        $this->ok('без неї відповідь веде у загальну скриньку', Notify::mailReplyTo() === 'shop@bofu.ua');
    }

    private function testNoHeaderInjection(): void
    {
        $this->group('Адреса не стає заголовком');
        foreach (["shop@bofu.ua\r\nBcc: chuzhyi@example.com", "shop@bofu.ua\nBcc: chuzhyi@example.com"] as $evil) {
            $this->ok('перенос рядка робить адресу негодящою', Notify::cleanAddress($evil) === '');
            $this->mail($evil);
            $used = Notify::mailFrom('order_new');
            $this->ok('і в лист вона не потрапляє', !str_contains($used, 'Bcc') && !str_contains($used, "\n"));
        }
        $this->mail('це не адреса');
        $this->ok('сміття у полі не ламає відправку',
            Notify::mailFrom('order_new') === 'shop@' . Notify::mailHost());
    }

    private function testHost(): void
    {
        $this->group('Домен для типових адрес');
        Settings::set('bot_site_url', 'https://www.bofu.ua/shop');
        // www. прибираємо: скриньки заводять на домені, а не на піддомені сайту
        $this->ok('береться з адреси сайту, без www', Notify::mailHost() === 'bofu.ua');
        Settings::set('bot_site_url', '');
        $this->ok('без неї — хост запиту або localhost', Notify::mailHost() !== '');
    }
}

return (new MailFromTest())->run();
