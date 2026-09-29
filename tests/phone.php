<?php
/**
 * Нормалізація телефону.  Запуск: php bin/cli.php test
 *
 * Номер їде далі в Нову Пошту, Telegram і Viber — усі вони хочуть E.164
 * (+380XXXXXXXXX), а людина набирає як звикла: з нулем, без нуля, з дужками,
 * з пробілами, з 8 попереду. Тут зафіксовано, що саме ми приймаємо й у що
 * перетворюємо, бо це правило продубльоване в браузері (assets/js/app.js).
 * Розʼїзд двох реалізацій виглядає як «форма не приймає правильний номер»,
 * тому будь-яка зміна тут має повторитись і там.
 */
declare(strict_types=1);

final class PhoneTest
{
    private int $pass = 0;
    private int $fail = 0;

    public function run(): int
    {
        $this->testUkrainian();
        $this->testGarbage();
        $this->testInternational();
        $this->testProblemText();
        $this->testIdempotent();

        echo "\n" . ($this->fail === 0
            ? "УСЕ ДОБРЕ: {$this->pass} перевірок\n"
            : "ПРОВАЛЕНО: {$this->fail} з " . ($this->pass + $this->fail) . "\n");
        return $this->fail === 0 ? 0 : 1;
    }

    private function ok(string $what, bool $cond): void
    {
        if ($cond) { $this->pass++; echo "  ok   $what\n"; }
        else { $this->fail++; echo "  FAIL $what\n"; }
    }

    private function group(string $name): void { echo "\n== $name ==\n"; }

    /** @param string|null $want null = номер має бути відкинутий */
    private function eq(string $in, ?string $want, bool $any = false): void
    {
        $got = $any ? AuthTokens::normPhoneAny($in) : AuthTokens::normPhone($in);
        $this->ok(
            "'$in' → " . ($want ?? 'відмова') . ($got === $want ? '' : ", а вийшло " . ($got ?? 'відмова')),
            $got === $want
        );
    }

    /** Як людина реально набирає український номер */
    private function testUkrainian(): void
    {
        $this->group('український номер у будь-якому записі');
        $want = '+380671234567';
        foreach ([
            '0671234567',            // як у телефонній книзі
            '067 123 45 67',         // з пробілами
            '067-123-45-67',         // з дефісами
            '(067) 123-45-67',       // з дужками
            '+380671234567',         // вже готовий
            '+38 (067) 123 45 67',   // готовий, але з розбивкою
            '380671234567',          // без плюса
            '671234567',             // без нуля й коду
            "  067 123 45 67  ",     // з пробілами по краях
        ] as $raw) {
            $this->eq($raw, $want);
        }
    }

    /**
     * Головне тут — «8» попереду. У старому форматі 8-067-… це той самий номер,
     * але цифр стає 11, і ми його НЕ вгадуємо: мовчки викинути «зайву» цифру
     * гірше, ніж попросити ввести ще раз — вгадаємо неправильно, і замовлення
     * поїде на чужий номер.
     */
    private function testGarbage(): void
    {
        $this->group('сміття не проходить');
        foreach (['', '   ', 'подзвоніть мені', '123', '00000', '067123456',
                  '80671234567', '06712345678', '+', '+38'] as $raw) {
            $this->eq($raw, null);
        }
        $this->ok('дуже довгий рядок не ламає нормалізацію',
            AuthTokens::normPhone(str_repeat('9', 500)) === null);
    }

    private function testInternational(): void
    {
        $this->group('закордонний покупець');
        $this->eq('+49 151 23456789', '+4915123456789', true);
        $this->eq('+1 (202) 555-0147', '+12025550147', true);
        $this->eq('+48 601 234 567', '+48601234567', true);
        // без плюса міжнародний не приймаємо: 4915123456789 не відрізнити від
        // помилки набору, а плюс — це явний намір людини вказати код країни
        $this->eq('4915123456789', null, true);
        // короткий і задовгий за межами E.164
        $this->eq('+49123', null, true);
        $this->eq('+4915123456789012', null, true);
        // українські правила діють і тут — normPhoneAny лише розширює normPhone
        $this->eq('067 123 45 67', '+380671234567', true);
        // Вузька половина правила закордонний номер відкидає — і саме тому
        // працювати з нею напряму не можна ніде, крім самої normPhoneAny.
        $this->eq('+49 151 23456789', null);

        // Український номер із зайвими цифрами — це НЕ закордонний номер.
        // Коду країни 380X не існує ні в кого, тож «+38063463513278» може бути
        // лише опискою: у міжнародну гілку такі пропускати не можна, інакше
        // замовлення поїде на номер, якого немає, а помітиться це при дзвінку.
        $this->group('український код країни має свою довжину');
        foreach (['+38063463513278', '38063463513278', '+3806712345678',
                  '+38067123456', '380671234567890'] as $raw) {
            $this->eq($raw, null, true);
        }
        $this->eq('+380634635132', '+380634635132', true);   // рівно 12 цифр — проходить
    }

    /** Пояснення для людини: одне «неправильний номер» не каже, що виправляти */
    private function testProblemText(): void
    {
        $this->group('чому номер не годиться — словами');
        $this->ok('придатний номер претензій не має',
            AuthTokens::phoneProblem('+380671234567') === null);
        $this->ok('порожній — так і сказано',
            str_contains((string)AuthTokens::phoneProblem('  '), 'порожній'));
        $ua = (string)AuthTokens::phoneProblem('+38063463513278');
        $this->ok('зайва цифра: названо, скільки їх нарахували', str_contains($ua, ' 11'));
        $this->ok('зайва цифра: показано приклад', str_contains($ua, '0671234567'));
        $this->ok('закордонний без плюса підказує про «+»',
            str_contains((string)AuthTokens::phoneProblem('4915123456789'), '+'));
        $this->ok('текст без цифр не вдає номер',
            str_contains((string)AuthTokens::phoneProblem('подзвоніть мені'), 'жодної цифри'));
    }

    /** Номер із бази, прогнаний ще раз, має лишитись собою — інакше Schema::backfill їх зіпсує */
    private function testIdempotent(): void
    {
        $this->group('повторна нормалізація нічого не міняє');
        foreach (['+380671234567', '+4915123456789'] as $norm) {
            $this->ok("'$norm' лишається собою",
                AuthTokens::normPhoneAny($norm) === $norm);
        }
    }
}

return (new PhoneTest())->run();
