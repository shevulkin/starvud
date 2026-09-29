<?php
/**
 * Смужка продажу на вітрині. Підключається лише коли режим увімкнено, тож
 * покупець цього коду не отримує взагалі.
 *
 * Сенс смужки — щоб продавець міг показати покупцеві сам сайт (фото, опис,
 * характеристики) і додавати товар звідти, не втрачаючи набране. Друга,
 * не менш важлива річ: у режимі, з якого не видно, що ти в ньому, люди
 * лишаються назавжди — і потім продають комусь чужий чек.
 */
?>
<div class="pos-bar" id="posBar">
  <span class="pos-bar-dot" aria-hidden="true"></span>
  <b class="pos-bar-title">Продаж</b>
  <span class="pos-bar-who"><?= e(Pos::label()) ?></span>
  <span class="pos-bar-sum">
    <span id="posBarCount"><?= (int)Pos::count() ?></span> поз. ·
    <b id="posBarTotal"><?= e(price_fmt(Pos::totals()['total'])) ?></b>
  </span>
  <span class="pos-bar-hint">Кнопки «У кошик» на сайті додають товар у цей чек</span>
  <div class="pos-bar-tools">
    <a class="btn btn-gold btn-xs" href="<?= e(url('/admin/orders/new')) ?>">До каси →</a>
    <form method="post" action="<?= e(url('/pos/off')) ?>" style="margin:0"><?= Csrf::field() ?>
      <input type="hidden" name="back" value="<?= e(request_path()) ?>">
      <button class="btn btn-line btn-xs" type="submit"
              onclick="return confirm('Скасувати продаж? Набраний чек зникне.')">Скасувати</button>
    </form>
  </div>
</div>
<script>
// Кошик міг змінитись без перезавантаження — тоді сума в смужці застаріла.
// Слухаємо подію вітрини й перепитуємо сервер: рахує він, а не ми.
(function () {
  // Смужка перекриває низ сторінки — звільняємо під неї місце, щоб вона не
  // ховала останній рядок і кнопку «Оформити» в кошику
  document.body.classList.add('pos-on');
  var url = '<?= e(url('/pos/state')) ?>';
  var count = document.getElementById('posBarCount');
  var total = document.getElementById('posBarTotal');
  document.addEventListener('bofu:cart', function () {
    fetch(url, { headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.active) return;
        if (count) count.textContent = d.count;
        if (total) total.textContent = d.total_label;
      }).catch(function () {});
  });
})();
</script>
