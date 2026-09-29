<?php
/**
 * Каса: три кроки — покупець, товари, отримання.
 *
 * Було одним екраном, і на ньому все справді вміщалось, але дивитись доводилось
 * усюди одразу: телефон покупця, плитка, доставка й кнопка оформлення в різних
 * кутах. Продаж — послідовна розмова («хто ви» → «що берете» → «як віддаємо»),
 * і екран тепер іде тим самим порядком.
 *
 * Кроки перемикаються в браузері, а не запитами: чек живе в сесії, і бігати за
 * ним на сервер щоразу, коли людина натиснула «Далі», нема потреби. Форма
 * лишається ОДНА на всі три кроки — оформлення відправляє все разом, тож поля
 * попередніх кроків нікуди не діваються й не потребують проміжного зберігання.
 *
 * Швидкість набору не постраждала: сканер, пошук і плитка живуть на другому
 * кроці разом і працюють як раніше — тап по плитці, скан, Enter.
 */
?>
<div class="admin-head"><h1 class="h-serif">Каса</h1>
  <?php if ($active): ?>
    <form method="post" action="<?= e(url('/admin/orders/new')) ?>" style="margin:0">
      <?= Csrf::field() ?><input type="hidden" name="_action" value="cancel">
      <button class="btn btn-line btn-sm" type="submit"
              onclick="return confirm('Скасувати продаж? Набраний чек зникне.')">Скасувати продаж</button>
    </form>
  <?php endif; ?>
</div>

<?php if (!$stores): ?>
  <div class="card-warn"><b>Вам не призначено жодної точки.</b>
    Продавати можна лише від імені магазину — попросіть адміністратора призначити вас у розділі «Користувачі».</div>
<?php else: ?>

<?php if ($errors): ?>
  <div class="flash" style="padding:0;margin:0 0 16px"><div class="flash-error">
    <?php foreach ($errors as $i => $err): ?><?= $i ? '<br>' : '' ?><?= e($err) ?><?php endforeach; ?>
  </div></div>
<?php endif; ?>

<?php /* Стрічка кроків. Це не прикраса: під кожним підписом стоїть те, що на
         цьому кроці вже вирішено, — кого записали, скільки позицій і на яку
         суму. Так продавцю не треба вертатись, щоб пригадати. */ ?>
<nav class="pos-steps" data-pos-steps>
  <button type="button" class="pos-step" data-go="1">
    <span class="pos-step-n">1</span>
    <span class="pos-step-t">Покупець</span>
    <span class="pos-step-s" data-sum-customer><?= e($customer['name'] !== '' ? $customer['name']
        : ($customer['phone'] !== '' ? $customer['phone'] : 'анонімний')) ?></span>
  </button>
  <button type="button" class="pos-step" data-go="2">
    <span class="pos-step-n">2</span>
    <span class="pos-step-t">Товари</span>
    <span class="pos-step-s" data-sum-goods><?= $lines
        ? count($lines) . ' поз. · ' . e(price_fmt($totals['total']))
        : 'чек порожній' ?></span>
  </button>
  <button type="button" class="pos-step" data-go="3">
    <span class="pos-step-n">3</span>
    <span class="pos-step-t">Отримання</span>
    <span class="pos-step-s" data-sum-delivery><?= e($source === 'offline' ? 'видача в точці' : 'доставка') ?></span>
  </button>
</nav>

<form method="post" action="<?= e(url('/admin/orders/new')) ?>" id="posForm">
  <?= Csrf::field() ?>
  <?php /* Крок памʼятається між перезавантаженнями: їх тут два — зміна точки
           продажу й повернення з помилкою оформлення. В обох випадках кидати
           людину на початок означало б відбирати зроблене. */ ?>
  <input type="hidden" name="step" id="posStep" value="<?= (int)$step ?>">

  <!-- ═══════════════════════════════════════════════ крок 1: покупець ═══ -->
  <section class="pos-pane" data-pane="1">
    <div class="admin-card" style="padding:18px">
      <h2 class="h-serif" style="font-size:19px;margin:0 0 4px">Хто покупець</h2>
      <p class="dim" style="margin:0 0 16px">
        Номер не обовʼязковий: продаж на місці цілком може бути анонімним.
        Якщо він є — покупка потрапить в історію цієї людини.
      </p>

      <div class="form-grid">
        <div data-help-title="Телефон покупця"
             data-help="Необовʼязково — і це головне в цьому полі. Продаж на місці цілком може бути анонімним: людина зайшла, купила, пішла.

Якщо номер є, натисніть «Знайти»: знайдемо акаунт — замовлення потрапить в історію покупок цієї людини; не знайдемо — заведемо новий акаунт при оформленні, і покупець зможе увійти на сайт цим самим номером.

Номер можна вписати будь-коли: тут, посеред набору чи перед самим оформленням — просто поверніться на цей крок.">
          <label>Телефон</label>
          <div style="display:flex;gap:8px;align-items:center">
            <input type="text" name="phone" id="posPhone" value="<?= e($customer['phone']) ?>"
                   placeholder="+380…" autocomplete="off" style="flex:1">
            <button type="button" class="btn btn-line btn-sm" data-pos-find>Знайти</button>
          </div>
        </div>
        <div>
          <label>Імʼя</label>
          <input type="text" name="name" id="posName" value="<?= e($customer['name']) ?>" autocomplete="off">
        </div>
      </div>

      <?php /* Стан покупця словами, а не кольором поля: «знайдено», «створимо
               акаунт» і «анонім» — три різні речі, і плутати їх дорого. Той
               самий блок повторюється на кроці оформлення, бо рішення ухвалюють
               саме там, а не тут. */ ?>
      <div class="pos-who is-<?= e($customer['state']) ?>" data-pos-note>
        <span class="pos-who-icon" data-pos-icon><?= e($customer['icon']) ?></span>
        <span data-pos-text><?= e($customer['note']) ?></span>
      </div>
    </div>

    <div class="admin-card" style="padding:18px">
      <h2 class="h-serif" style="font-size:19px;margin:0 0 4px">Від якої точки продаємо</h2>
      <p class="dim" style="margin:0 0 14px">
        З її складу спишеться товар, її ціни й акції побачить покупець, і їй дістанеться замовлення.
      </p>
      <div style="max-width:340px" data-help-title="Точка продажу"
           data-help="Магазин, від імені якого йде продаж: з його складу спишеться товар і йому дістанеться замовлення.

Ціни теж його — у точки може бути власний цінник і власна акція, тому після зміни магазину плитка й чек перераховуються.

Поки чек порожній, точку можна міняти вільно; коли товар уже набрано — краще не чіпати.">
        <select name="store_id" data-pos-store>
          <?php foreach ($stores as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= (int)$store_id === (int)$s['id'] ? 'selected' : '' ?>>
              <?= e($s['name'] . ($s['city'] ? ' — ' . $s['city'] : '')) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="pos-nav">
      <span class="dim">Крок 1 із 3</span>
      <button type="button" class="btn btn-gold" data-go="2">Далі: товари →</button>
    </div>
  </section>

  <!-- ═════════════════════════════════════════════════ крок 2: товари ═══ -->
  <section class="pos-pane" data-pane="2">
    <div class="pos-wrap">
      <div class="pos-goods">
        <div class="admin-card" style="padding:16px">
          <div class="pos-top">
            <div class="np-wrap" style="flex:1;min-width:220px" data-help-title="Сканер і пошук"
                 data-help="Поле працює трьома способами.

USB-СКАНЕР: піднесіть сканер до етикетки — він сам надрукує код і натисне Enter, позиція одразу ляже в чек. Тримайте курсор у цьому полі, і більше нічого робити не треба: сканер для компʼютера — це просто клавіатура.

КАМЕРА: кнопка «Камера» поруч. Працює на телефоні (Chrome на Android) — відкрийте касу з нього й наводьте камеру на етикетки, позиції додаватимуться одна за одною.

ПОШУК: наберіть частину назви — нижче зʼявиться список фасовок із цінами й залишками, клік додає в чек.

Щоб сканування взагалі щось знаходило, у товарів мають бути заповнені коди: «Каталог → Коди й штрихкоди».">
              <label>Сканер або пошук</label>
              <input type="text" id="posScan" autocomplete="off"
                     placeholder="код зі сканера або назва товару">
            </div>
            <button type="button" class="btn btn-line btn-sm" id="posCam">📷 Камера</button>
          </div>
          <p class="dim" id="posMsg" style="margin:10px 0 0;min-height:18px"></p>
          <?php if (!$has_codes): ?>
            <?php /* Сканер, якому нема чого знаходити, виглядає зламаним. Кажемо
                     прямо й ведемо туди, де це чинять — одним кліком. */ ?>
            <p class="field-hint is-bad" style="margin-top:6px">
              Жоден товар ще не має коду — сканер поки не знайде нічого.
              <a href="<?= e(url('/admin/products/codes')) ?>">Заповнити коди й штрихкоди</a>
            </p>
          <?php endif; ?>
        </div>

        <?php /* Категорія перемикається на місці, а не посиланням. Посилання —
                 це відкриття сторінки заново, а разом із ним повернення на
                 перший крок і втрата всього, що вже набрано у формі: продавець
                 тицяв фільтр, а опинявся на «хто покупець».

                 Вибрана категорія їде прихованим полем у формі, щоб пережити ті
                 два перезавантаження, які в каси таки бувають, — зміну точки й
                 повернення з помилкою оформлення. */ ?>
        <?php /* Підрозділи стоять тут одним рядом із розділами, а не за стрілкою,
                 як у вітрині: каса — це один тап на дію, і зайвий крок «розгорнути»
                 коштував би дорожче за довший ряд, який і так прокручується вбік.
                 Розділ на касі показує всю свою гілку — і власні товари, і сортові. */ ?>
        <?php if ($cats): ?>
          <div class="cat-chips pos-cats" data-pos-cats style="margin-bottom:12px">
            <button type="button" class="chip <?= $cat ? '' : 'active' ?>" data-pos-cat="0">Усі</button>
            <?php foreach ($cats as $c): ?>
              <button type="button" class="chip<?= ($c['depth'] ?? 0) ? ' chip-sub' : '' ?><?= $cat === (int)$c['id'] ? ' active' : '' ?>"
                      data-pos-cat="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <input type="hidden" name="cat" id="posCat" value="<?= (int)$cat ?>">

        <?php /* Плитка: тап = +1 у чек. На кількох десятках позицій це швидше за
                 будь-який пошук, і саме так влаштовані каси в невеликих магазинах. */ ?>
        <div class="pos-tiles" data-help-title="Плитка товарів"
             data-help="Кожна плитка — одна фасовка. Тап додає одну штуку в чек; тапнули двічі — буде дві.

Ціна й залишок показані для вибраної точки. Нуль не забороняє продаж (товар можуть виготовити під замовлення), але означає, що склад розійшовся з дійсністю — краще виправити залишок.

Плитку можна звузити категорією вгорі.">
          <?php foreach ($tiles as $t): ?>
            <button type="button" class="pos-tile<?= $t['stock'] <= 0 ? ' is-empty' : '' ?>"
                    data-pos-add data-product="<?= $t['product_id'] ?>" data-variant="<?= $t['variant_id'] ?>">
              <span class="pos-tile-photo" style="background-image:url('<?= e(asset($t['photo'])) ?>')"></span>
              <span class="pos-tile-name"><?= e($t['title']) ?></span>
              <?php if ($t['variant_name'] !== ''): ?>
                <span class="pos-tile-var"><?= e($t['variant_name']) ?></span>
              <?php endif; ?>
              <span class="pos-tile-foot">
                <b><?= e(price_fmt($t['price'])) ?></b>
                <span class="<?= $t['stock'] > 0 ? 'dim' : 'pos-tile-zero' ?>">
                  <?= $t['stock'] > 0 ? (int)$t['stock'] . ' шт.' : ($t['made_to_order'] ? 'під замовлення' : 'немає') ?>
                </span>
              </span>
            </button>
          <?php endforeach; ?>
          <?php if (!$tiles): ?><p class="dim">У цій категорії немає активних товарів.</p><?php endif; ?>
        </div>
      </div>

      <?php /* Чек. На великому екрані він стоїть праворуч від плитки й весь час
               перед очима. На телефоні місця для другої колонки немає, і чек
               перетворюється на смужку внизу: видно, скільки позицій і на яку
               суму, а сам список розкривається дотиком. Так на екран одразу
               потрапляють товари — те, з чим працюють, — а не список набраного,
               заради якого раніше доводилось прокручувати півекрана. */ ?>
      <div class="pos-check pos-cart" data-pos-cart>
        <div class="pos-cart-bar">
          <button type="button" class="pos-cart-peek" data-pos-cart-toggle aria-expanded="false">
            <span class="pos-cart-caret" aria-hidden="true">▲</span>
            <span>Чек · <b data-sum-count><?= count($lines) ?></b> поз.</span>
            <b class="pos-cart-sum" data-sum-total><?= e(price_fmt($totals['total'])) ?></b>
          </button>
          <button type="button" class="btn btn-gold btn-sm" data-go="3">Далі →</button>
        </div>
        <div class="pos-cart-body">
          <div class="admin-card pos-lines-card" style="padding:16px">
            <h2 class="h-serif" style="font-size:18px;margin-bottom:12px">Чек</h2>
            <table class="tbl pos-lines" id="posLines">
              <?php foreach ($lines as $r): ?>
                <tr>
                  <td>
                    <?= e($r['product']['name']) ?>
                    <?php if ($r['variant']): ?><div class="dim"><?= e($r['variant']['name']) ?></div><?php endif; ?>
                  </td>
                  <td class="num" style="white-space:nowrap">
                    <button type="button" class="pos-qty-btn" data-pos-qty="<?= e($r['key']) ?>" data-to="<?= (int)$r['qty'] - 1 ?>">−</button>
                    <b><?= (int)$r['qty'] ?></b>
                    <button type="button" class="pos-qty-btn" data-pos-qty="<?= e($r['key']) ?>" data-to="<?= (int)$r['qty'] + 1 ?>">+</button>
                  </td>
                  <td class="num"><?= e(price_fmt($r['sum'])) ?></td>
                  <td><button type="button" class="btn btn-line btn-xs" data-pos-qty="<?= e($r['key']) ?>" data-to="0">×</button></td>
                </tr>
              <?php endforeach; ?>
            </table>
            <p class="dim" id="posEmpty" <?= $lines ? 'hidden' : '' ?> style="margin:0">
              Чек порожній. Тапніть по плитці, піднесіть сканер до етикетки — або вийдіть на сайт і додавайте звідти.
            </p>
            <div class="pos-total">Разом <b id="posTotal"><?= e(price_fmt($totals['total'])) ?></b></div>
          </div>

          <div class="pos-nav">
            <button type="button" class="btn btn-line" data-go="1">← Покупець</button>
            <button type="button" class="btn btn-gold" data-go="3">Далі: отримання →</button>
          </div>
          <a class="btn btn-line" style="width:100%;margin-top:10px" href="<?= e(url('/shop')) ?>"
             data-help-title="Кнопка «Вийти на сайт»"
             data-help="Відкриває вітрину, не втрачаючи чек: унизу зʼявиться смужка продажу, і кнопки «У кошик» на сайті додаватимуть товар у цей самий чек.

Це для випадку, коли покупцеві треба показати картку товару — фото, опис, характеристики. Повернутись до каси можна з тієї ж смужки.">Вийти на сайт →</a>
        </div>
      </div>
    </div>
  </section>

  <!-- ══════════════════════════════════════════════ крок 3: отримання ═══ -->
  <section class="pos-pane" data-pane="3">
    <div class="pos-wrap">
      <div class="pos-goods">
        <div class="admin-card" style="padding:18px">
          <h2 class="h-serif" style="font-size:19px;margin:0 0 14px">Як покупець отримає замовлення</h2>

          <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:14px" data-help-title="Спосіб продажу"
               data-help="«У магазині» — покупець перед вами: товар віддається одразу, доставка не потрібна.

«Телефоном» — подзвонили. Тоді номер обовʼязковий: без нього замовлення нікому підтвердити.

Від вибору залежать поля доставки нижче й те, чи закривається замовлення одразу.">
            <?php foreach (['offline' => 'У магазині', 'phone' => 'Телефоном'] as $key => $label): ?>
              <label class="toggle" style="gap:8px">
                <input type="radio" name="source" value="<?= e($key) ?>" data-pos-source
                       style="position:static;opacity:1" <?= $source === $key ? 'checked' : '' ?>>
                <?= e($label) ?></label>
            <?php endforeach; ?>
          </div>

          <div data-pos-delivery <?= $source === 'offline' ? 'hidden' : '' ?>>
            <label>Доставка</label>
            <div style="display:flex;gap:14px;flex-wrap:wrap;margin:6px 0 12px">
              <?php foreach (OrderFlow::DELIVERY as $key => $label): ?>
                <label class="toggle" style="gap:8px">
                  <input type="radio" name="delivery" value="<?= e($key) ?>" data-pos-dlv
                         style="position:static;opacity:1" <?= $form['delivery'] === $key ? 'checked' : '' ?>>
                  <?= e($label) ?></label>
              <?php endforeach; ?>
            </div>
            <div data-pos-np <?= $form['delivery'] === 'np' ? '' : 'hidden' ?>>
              <?php /* Кожне поле з підказками — у власній обгортці. Віджет
                       кріпить випадний список до батьківського елемента поля, і
                       без обгортки він чіплявся до всього блоку доставки: список
                       міст випадав аж під адресою, за півекрана від того місця,
                       де його чекають. */ ?>
              <div class="pos-field">
                <label>Місто</label>
                <input type="text" name="np_city" id="npCity" value="<?= e($form['city']) ?>" autocomplete="off">
              </div>
              <input type="hidden" name="city_ref" id="npCityRef" value="<?= e($form['city_ref']) ?>">
              <input type="hidden" name="np_office_ref" id="npOfficeRef" value="<?= e($form['np_office_ref']) ?>">
              <input type="hidden" name="np_street_ref" id="npStreetRef" value="<?= e($form['np_street_ref']) ?>">
              <?php /* Куди везти: у відділення чи додому. Обирати з довідника
                       обовʼязково — накладна створюється за посиланням, а не за назвою. */ ?>
              <label style="margin-top:8px">Куди</label>
              <select name="np_type" id="posNpType">
                <option value="warehouse"<?= $form['np_type'] === 'warehouse' ? ' selected' : '' ?>>У відділення / поштомат</option>
                <option value="courier"<?= $form['np_type'] === 'courier' ? ' selected' : '' ?>>Курʼєром на адресу</option>
              </select>
              <div data-pos-np-wh<?= $form['np_type'] === 'courier' ? ' hidden' : '' ?>>
                <div class="pos-field" style="margin-top:8px">
                  <label>Відділення</label>
                  <input type="text" name="np_office" id="npOffice" value="<?= e($form['np_office']) ?>" autocomplete="off">
                </div>
              </div>
              <div data-pos-np-courier<?= $form['np_type'] === 'courier' ? '' : ' hidden' ?>>
                <div class="pos-field" style="margin-top:8px">
                  <label>Вулиця</label>
                  <input type="text" name="np_street" id="npStreet" value="<?= e($form['np_street']) ?>" autocomplete="off">
                </div>
                <div style="display:flex;gap:8px;margin-top:8px">
                  <div style="flex:1"><label>Будинок</label>
                    <input type="text" name="np_house" id="npHouse" value="<?= e($form['np_house']) ?>" maxlength="20"></div>
                  <div style="flex:1"><label>Квартира</label>
                    <input type="text" name="np_flat" id="npFlat" value="<?= e($form['np_flat']) ?>" maxlength="20"></div>
                </div>
              </div>
            </div>
            <div data-pos-addr <?= $form['delivery'] === 'other' ? '' : 'hidden' ?>>
              <label>Адреса</label>
              <input type="text" name="address" value="<?= e($form['address']) ?>">
            </div>
            <div style="margin-top:8px">
              <label>Email (необовʼязково)</label>
              <input type="text" name="email" value="<?= e($form['email']) ?>">
            </div>
          </div>

          <div class="form-grid" style="margin-top:14px">
            <div data-help-title="Промокод"
                 data-help="Код, який назвав покупець. Перевіряється так само, як на сайті: строк дії, ліміт використань і ліміт «один раз на людину» (його рахуємо за номером телефону).

Неробочий код не пропустить оформлення — побачите причину вгорі екрана. Знижку рахує сервер за тими ж правилами, що й у кошику.">
              <label>Промокод</label>
              <input type="text" name="promo_code" value="<?= e($form['promo_code']) ?>">
            </div>
            <div>
              <label>Коментар</label>
              <input type="text" name="comment" value="<?= e($form['comment']) ?>" placeholder="як домовились">
            </div>
          </div>

          <div style="margin-top:14px" data-pos-handed <?= $form['delivery'] === 'pickup' ? '' : 'hidden' ?>
               data-help-title="Товар віддано покупцю"
               data-help="Ставте, коли товар уже в руках покупця: замовлення одразу закриється статусом «Доставлено», а залишки спишуться зі складу точки.

Знімайте, якщо людина ще прийде забрати (відклали, домовились на завтра) — тоді замовлення лишиться в роботі, і магазин отримає про нього сповіщення, як про будь-яке нове.">
            <label class="toggle">
              <input type="checkbox" name="handed" <?= $form['handed'] ? 'checked' : '' ?>><span class="tr"></span>
              Товар віддано покупцю</label>
          </div>

          <?php /* Оплата питається лише там, де гроші беруть просто зараз:
                   видача в точці. Замовлення на доставку оплатять при
                   отриманні, і питати про це тут — питати навздогад. */ ?>
          <?php if ($kasa_on): ?>
            <div style="margin-top:14px" data-pos-pay <?= $form['delivery'] === 'pickup' ? '' : 'hidden' ?>
                 data-help-title="Чим розрахувались"
                 data-help="Йде у фіскальний чек: ДПС має бачити, готівка це чи картка.

Готівкою — впишіть, скільки дали купюрами, і каса покаже решту. Порожнє поле означає «дали рівно стільки, скільки в чеку».

Готівкова сума заокруглюється до 10 копійок (монет дрібніших немає) — округлення йде в чек окремим рядком, а не тихою зміною ціни.">
              <label>Чим розрахувались</label>
              <div class="pos-pays" style="display:flex;gap:14px;flex-wrap:wrap;margin-top:6px">
                <?php foreach ($pay_types as $code => $label): ?>
                  <label class="checkbox" style="margin:0">
                    <input type="radio" name="pay_type" value="<?= (int)$code ?>" data-pos-pay-type
                           <?= (int)$form['pay_type'] === (int)$code ? 'checked' : '' ?>>
                    <?= e($label) ?></label>
                <?php endforeach; ?>
              </div>
              <div style="display:flex;gap:10px;align-items:flex-end;margin-top:8px"
                   data-pos-cash <?= (int)$form['pay_type'] === 0 ? '' : 'hidden' ?>>
                <div style="flex:1">
                  <label>Отримано готівкою</label>
                  <input type="text" name="got" value="<?= e($form['got']) ?>" inputmode="decimal"
                         placeholder="без решти" data-pos-got>
                </div>
                <div style="flex:1" class="dim" data-pos-change-box hidden>
                  Решта: <b data-pos-change>—</b>
                </div>
                <?php /* Сума чека числом — решту рахує браузер, і брати її з
                         відформатованого «1 250,50 грн» означало б розбирати
                         пробіли й коми там, де сервер уже все порахував. */ ?>
                <input type="hidden" data-pos-total value="<?= e(number_format((float)$totals['total'], 2, '.', '')) ?>">
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <?php /* Підсумок перед кнопкою: що саме зараз оформиться. Повертатись на
               другий крок заради перевірки суми не має бути потреби. */ ?>
      <div class="pos-check">
        <div class="admin-card" style="padding:16px">
          <h2 class="h-serif" style="font-size:18px;margin-bottom:10px">До оформлення</h2>
          <div class="pos-sum"><span class="dim">Позицій у чеку</span><b data-sum-count><?= count($lines) ?></b></div>
          <div class="pos-total">Разом <b data-sum-total><?= e(price_fmt($totals['total'])) ?></b></div>

          <div class="pos-who is-<?= e($customer['state']) ?>" data-pos-note>
            <span class="pos-who-icon" data-pos-icon><?= e($customer['icon']) ?></span>
            <span data-pos-text><?= e($customer['note']) ?></span>
          </div>

          <div class="pos-actions" style="margin-top:14px">
            <button class="btn btn-gold" type="submit" name="_action" value="save">💾 Оформити замовлення</button>
          </div>
        </div>
        <div class="pos-nav">
          <button type="button" class="btn btn-line" data-go="2">← Товари</button>
        </div>
      </div>
    </div>
  </section>
</form>

<?php if ($np_enabled): ?><?= View::partial('partials/np_autocomplete') ?><?php endif; ?>
<script>
window.POS = {
  base: '<?= e(url('/')) ?>',
  csrf: '<?= e(Csrf::token()) ?>',
  postUrl: '<?= e(url('/admin/orders/new')) ?>',
  searchUrl: '<?= e(url('/admin/orders/search')) ?>',
  tilesUrl: '<?= e(url('/admin/orders/tiles')) ?>'
};
</script>
<?php /* Читання штрихкодів своїми силами — потрібне там, де браузер не вміє
         сам (а на Windows не вміє жоден). Вантажимо лише там, де сканують. */ ?>
<script src="<?= e(asset_v('js/barcode.js')) ?>" defer></script>
<script src="<?= e(asset_v('js/scan.js')) ?>" defer></script>
<script src="<?= e(asset_v('js/pos.js')) ?>" defer></script>
<?php endif; ?>
