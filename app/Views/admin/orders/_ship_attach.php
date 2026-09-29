<?php /* Вписати номер накладної, створеної в кабінеті НП. Не «запасний варіант
        на випадок поломки», а звичайний шлях: частину посилок оформлюють просто
        на відділенні. Далі все працює однаково — трекінгу потрібен лише номер.
        Підключається з двох місць картки, тому окремим файлом.
        Очікує в області видимості: $order, $cid. */ ?>
<form method="post" action="<?= e(url('/admin/orders/' . $order['id'])) ?>"
      style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-top:8px">
  <?= Csrf::field() ?>
  <input type="hidden" name="action" value="ship_attach">
  <input type="hidden" name="order_id" value="<?= (int)$cid ?>">
  <div style="width:200px">
    <label class="dim" style="font-size:12px">Номер накладної</label>
    <input type="text" name="ttn" placeholder="20450000000000" inputmode="numeric" autocomplete="off" maxlength="20">
  </div>
  <button class="btn btn-line btn-sm" type="submit"
          data-help-title="Прикріпити номер"
          data-help="Для накладних, створених у кабінеті чи застосунку Нової Пошти — або просто на відділенні.

Впишіть 14 цифр номера. Ми одразу спитаємо НП, що це за посилка: якщо номер живий, тут зʼявиться її статус, а покупцю піде повідомлення з номером для відстеження.

Далі все як зі створеною тут: статус оновлюється сам, покупець бачить рух у своєму кабінеті.">Прикріпити</button>
</form>
