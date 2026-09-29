<div class="admin-head"><h1 class="h-serif">Сповіщення: події та тексти</h1></div>
<p class="dim" style="margin-bottom:18px">Для кожної події можна увімкнути канали, вибрати одержувачів і змінити текст повідомлення. Підстановки в фігурних дужках замінюються реальними даними.</p>
<form method="post" action="<?= e(url('/admin/notifications')) ?>">
  <?= Csrf::field() ?>
  <?php foreach ($by_event as $event => $rules): ?>
    <div class="admin-card">
      <h2 class="h-serif"><?= e($event_labels[$event] ?? $event) ?></h2>
      <p class="dim" style="margin-bottom:14px">Доступні підстановки: <code><?= e($vars_hint[$event] ?? '') ?></code></p>
      <table class="tbl">
        <tr>
          <th style="width:110px" data-help-title="Колонка «Канал»"
              data-help="Яким шляхом піде повідомлення: Telegram, Viber, email або пуш у браузер.

Канал працює лише тоді, коли він налаштований у «Налаштуваннях», а в одержувача привʼязаний акаунт. Увімкнений тут перемикач сам по собі повідомлення не доставить.

Для кожної події канали налаштовуються окремо: про нове замовлення можна писати в Telegram, а про зміну статусу — ні.">Канал</th>
          <th style="width:90px" data-help-title="Колонка «Увімкнено»"
              data-help="Чи надсилати повідомлення цим каналом для цієї події.

Вимкнено — повідомлення не піде взагалі, навіть якщо канал налаштований і текст заповнений.

Не вмикайте все підряд: якщо про кожну дрібницю приходить чотири повідомлення в чотири канали, персонал швидко перестає їх читати — і пропускає важливе.">Увімкнено</th>
          <th style="width:200px" data-help-title="Колонка «Кому»"
              data-help="Хто отримає повідомлення.

«Лише адміни» — усі адміністратори мережі.

«Продавці магазину» — тільки ті, хто веде саме ту точку, якої стосується подія. Для замовлень це найточніший варіант: продавець не отримує чужих сповіщень.

«Адміни + продавці» — і ті, і ті. Беріть для важливого, де потрібен подвійний контроль.

«Покупцю» вибрати не можна: так позначені події, адресовані одній конкретній людині — тій, чиє це замовлення чи очікування товару. Розсилати таке персоналу немає сенсу, а всім покупцям — тим паче.">Кому</th>
          <th data-help-title="Колонка «Текст повідомлення»"
              data-help="Що саме прийде людині. Слова у фігурних дужках підставляються автоматично: {number} стане номером замовлення, {total} — сумою.

Які саме підстановки доступні для цієї події, написано над таблицею — використовуйте тільки їх, інші лишаться в тексті як є.

Пишіть так, щоб було зрозуміло з екрана блокування: найважливіше — на початку. Продавцю потрібні номер, склад замовлення й телефон, а не ввічливе вступне речення.">Текст повідомлення</th></tr>
        <?php foreach ($rules as $r): ?>
          <tr>
            <td><b><?= e($channel_labels[$r['channel']] ?? $r['channel']) ?></b></td>
            <td style="text-align:center">
              <label class="toggle"><input type="checkbox" name="rule[<?= (int)$r['id'] ?>][enabled]" <?= $r['enabled'] ? 'checked' : '' ?>><span class="tr"></span></label>
            </td>
            <td>
              <?php if (Notify::isCustomerEvent($event)): ?>
                <?php /* Одержувач тут не вибір, а суть події: вона адресована одній
                         конкретній людині — тій, чиє це замовлення чи очікування. */ ?>
                <b>Покупцю</b>
                <div class="dim">Тому, кого подія стосується</div>
              <?php else: ?>
                <select name="rule[<?= (int)$r['id'] ?>][recipients]">
                  <option value="admins" <?= $r['recipients'] === 'admins' ? 'selected' : '' ?>>Лише адміни</option>
                  <option value="sellers" <?= $r['recipients'] === 'sellers' ? 'selected' : '' ?>>Продавці магазину</option>
                  <option value="admins_sellers" <?= $r['recipients'] === 'admins_sellers' ? 'selected' : '' ?>>Адміни + продавці</option>
                </select>
              <?php endif; ?>
            </td>
            <td><textarea name="rule[<?= (int)$r['id'] ?>][template]" rows="2" style="min-width:260px"><?= e($r['template']) ?></textarea></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endforeach; ?>
  <div class="admin-save">
    <button class="btn btn-gold" type="submit">💾 Зберегти правила</button>
    <span class="admin-save-note"></span>
  </div>
</form>
