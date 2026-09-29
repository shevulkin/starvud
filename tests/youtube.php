<?php
/**
 * Добірка відео з YouTube.  Запуск: php bin/cli.php test
 *
 * Мережі тут немає: перевіряємо дві чисті функції, у яких і живе вся поведінка.
 *
 * Задача, яку вони вирішують: RSS каналу віддає лише 15 останніх завантажень, і
 * Shorts лежать у ньому поряд зі звичайними відео. На каналі, де Shorts виходять
 * по кілька на тиждень, вони займають майже всі 15 позицій — і довгих відео на
 * сайті лишається два. Саме це й було видно на бойовому сайті.
 */
declare(strict_types=1);

final class YouTubeTest
{
    private int $pass = 0;
    private int $fail = 0;

    public function run(): int
    {
        $this->testAccumulates();
        $this->testShortsNotAccumulated();
        $this->testLongFirst();
        $this->testShortsOnlyFillGap();
        $this->testOrder();

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

    /** @return array один запис у тому вигляді, у якому його будує fetch() */
    private function v(string $id, string $date, bool $short = false): array
    {
        return ['id' => $id, 'title' => 'Відео ' . $id, 'url' => 'https://youtu.be/' . $id,
                'thumb' => 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg',
                'published' => $date, 'is_short' => $short];
    }

    private function ids(array $items): array { return array_column($items, 'id'); }

    /** Головне, заради чого все робилось: старе відео не зникає зі списку */
    private function testAccumulates(): void
    {
        echo "== довгі відео накопичуються ==\n";
        $cached = [$this->v('old1', '2026-01-10'), $this->v('old2', '2026-02-20')];
        // у стрічці його вже немає — витіснили нові Shorts
        $feed = [$this->v('new1', '2026-08-08'), $this->v('s1', '2026-08-07', true)];

        $merged = YouTube::merge($cached, $feed);
        $ids = $this->ids($merged);
        $this->ok(in_array('old1', $ids, true), 'відео, якого вже немає у стрічці, лишилось');
        $this->ok(in_array('new1', $ids, true), 'нове відео додалося');
        $this->ok(count(array_unique($ids)) === count($ids), 'повторів немає');

        // повторне оновлення тим самим вмістом нічого не ламає
        $again = YouTube::merge($merged, $feed);
        $this->ok($this->ids($again) === $ids, 'повторне оновлення не змінює список');
    }

    /** Інакше за місяць добірка складалася б із самих Shorts */
    private function testShortsNotAccumulated(): void
    {
        echo "== Shorts не накопичуються ==\n";
        $cached = [$this->v('sOld', '2026-07-01', true), $this->v('long', '2026-06-01')];
        $feed = [$this->v('sNew', '2026-08-08', true)];

        $ids = $this->ids(YouTube::merge($cached, $feed));
        $this->ok(!in_array('sOld', $ids, true), 'старий Short зник зі сховища');
        $this->ok(in_array('sNew', $ids, true), 'свіжий Short на місці');
        $this->ok(in_array('long', $ids, true), 'довге відео при цьому збереглося');
    }

    private function testLongFirst(): void
    {
        echo "== спершу довгі ==\n";
        $items = [$this->v('s1', '2026-08-08', true), $this->v('L1', '2026-01-01')];
        $out = YouTube::pick($items, 2);
        $this->ok($this->ids($out)[0] === 'L1', 'довге відео попереду, навіть якщо Short новіший');
    }

    /** «Якщо відео немає» — саме та умова, заради якої Shorts тут узагалі є */
    private function testShortsOnlyFillGap(): void
    {
        echo "== Shorts лише добирають ==\n";
        $shorts = [$this->v('s1', '2026-08-08', true), $this->v('s2', '2026-08-07', true),
                   $this->v('s3', '2026-08-06', true), $this->v('s4', '2026-08-05', true)];

        $two = array_merge([$this->v('L1', '2026-03-01'), $this->v('L2', '2026-02-01')], $shorts);
        $out = YouTube::pick($two, 6);
        $this->ok(count($out) === 6, 'ряд заповнений повністю');
        $this->ok(count(array_filter($out, fn($v) => !$v['is_short'])) === 2, 'обидва довгих показані');
        $this->ok(count(array_filter($out, fn($v) => $v['is_short'])) === 4, 'решту добрали Shorts');

        // а коли довгих вистачає — Shorts зникають самі, без перемикача
        $six = [];
        for ($i = 1; $i <= 6; $i++) $six[] = $this->v('L' . $i, '2026-0' . $i . '-01');
        $out = YouTube::pick(array_merge($six, $shorts), 6);
        $this->ok(count(array_filter($out, fn($v) => $v['is_short'])) === 0,
            'коли довгих шість — жодного Short');

        $this->ok(YouTube::pick([], 6) === [], 'порожній канал не падає');
    }

    private function testOrder(): void
    {
        echo "== порядок за датою ==\n";
        $merged = YouTube::merge([], [
            $this->v('a', '2026-03-01'), $this->v('c', '2026-08-01'), $this->v('b', '2026-05-01'),
        ]);
        $this->ok($this->ids($merged) === ['c', 'b', 'a'], 'новіші попереду');
    }
}

return (new YouTubeTest())->run();
