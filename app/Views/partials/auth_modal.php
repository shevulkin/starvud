<?php
/*
 * Вхід. Один основний шлях — пошта з одноразовим кодом: він працює для всіх і
 * не вимагає ні Google, ні месенджера. Решта способів (якщо налаштовані)
 * згорнуті в «Інші способи», щоб не перетворювати вікно на анкету.
 *
 * Окремої реєстрації немає свідомо: акаунт створює перше введення коду
 * (див. EmailAuth), паролів у системі не існує. Про це — один короткий рядок.
 */
$google = GoogleAuth::configured();
// Google — головним способом, над поштою: це вхід в один дотик без листа й коду.
// Решта способів лишаються в згорнутому списку.
$otherWays = Telegram::configured() || Viber::configured();
?>
<div class="modal-back" id="authModal">
  <div class="modal sv-auth" role="dialog" aria-modal="true" aria-labelledby="authTitle">
    <button class="sv-auth-x" id="authClose" type="button" aria-label="Закрити">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
    <h3 id="authTitle">Вхід</h3>
    <p class="sv-auth-sub">Замовлення, адреси й сповіщення в одному місці. Купувати можна й без входу.</p>

    <div id="loginHint" class="auth-note" role="status" aria-live="polite" style="display:none"></div>

    <?php if ($google): ?>
      <a class="btn btn-line sv-auth-google" href="<?= e(url('/auth/google')) ?>">
        <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.6l6.8-6.8C35.8 2.4 30.3 0 24 0 14.6 0 6.5 5.4 2.6 13.3l7.9 6.1C12.4 13.6 17.7 9.5 24 9.5z"/><path fill="#4285F4" d="M46.1 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.4c-.5 2.9-2.2 5.3-4.6 7l7.2 5.6c4.3-4 6.8-9.9 6.8-17.1z"/><path fill="#FBBC05" d="M10.5 28.6c-.5-1.4-.8-2.9-.8-4.6s.3-3.2.8-4.6l-7.9-6.1C.9 16.5 0 20.1 0 24s.9 7.5 2.6 10.7l7.9-6.1z"/><path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.2-5.6c-2 1.4-4.6 2.2-8.7 2.2-6.3 0-11.6-4.1-13.5-9.9l-7.9 6.1C6.5 42.6 14.6 48 24 48z"/></svg>
        Увійти через Google</a>
      <p class="sv-auth-or"><span>або поштою</span></p>
    <?php endif; ?>

    <form id="emailLoginBox" class="sv-auth-form" onsubmit="return false">
      <label class="sv-auth-l" for="emailInput">Пошта</label>
      <input type="email" id="emailInput" placeholder="Ваша електронна пошта" autocomplete="email" required>
      <div id="emailCodeField" style="display:none">
        <label class="sv-auth-l" for="emailCodeInput">Код з листа</label>
        <input type="text" id="emailCodeInput" placeholder="6 цифр" inputmode="numeric" autocomplete="one-time-code" maxlength="6">
      </div>
      <button class="btn btn-gold sv-auth-main" id="emailSendBtn" type="button">Отримати код</button>
      <button class="btn btn-gold sv-auth-main" id="emailVerifyBtn" type="button" style="display:none">Увійти</button>
      <p class="sv-auth-note">Немає акаунта — створиться сам. Продовжуючи, ви погоджуєтесь із
        <a href="<?= e(url('/privacy')) ?>" target="_blank" rel="noopener">політикою конфіденційності</a>.
        <button class="sv-auth-link" id="emailResendBtn" type="button" style="display:none">Надіслати код ще раз</button></p>
    </form>

    <?php if ($otherWays): ?>
      <details class="sv-auth-more">
        <summary>Інші способи входу</summary>
        <div class="stack">
          <?php if (Telegram::configured()): ?>
            <button class="btn btn-line" id="tgLoginBtn" type="button">Через Telegram</button>
          <?php endif; ?>
          <?php /* Входу через Viber немає навмисно: Viber не доводить, що номер
                   належить співрозмовнику. Він лише доставляє код за номером. */ ?>
          <?php if (Telegram::configured() || Viber::configured()): ?>
            <button class="btn btn-line" id="phoneLoginBtn" type="button">За номером телефону</button>
          <?php endif; ?>
        </div>
        <div id="phoneLoginBox" style="display:none;margin-top:12px">
          <label class="sv-auth-l" for="phoneInput">Номер телефону</label>
          <input type="tel" id="phoneInput" placeholder="067 123 45 67">
          <div id="codeField" style="display:none">
            <label class="sv-auth-l" for="codeInput">Код з месенджера</label>
            <input type="text" id="codeInput" placeholder="6 цифр" inputmode="numeric">
          </div>
          <button class="btn btn-gold sv-auth-main" id="phoneSendBtn" type="button">Отримати код</button>
          <button class="btn btn-gold sv-auth-main" id="codeVerifyBtn" type="button" style="display:none">Увійти</button>
          <button class="sv-auth-link" id="phoneResendBtn" type="button" style="display:none">Надіслати ще раз</button>
        </div>
      </details>
    <?php endif; ?>
  </div>
</div>
<script>
(function(){
  var base = '<?= e(url('/')) ?>'.replace(/\/$/, '');
  var csrf = '<?= e(Csrf::token()) ?>';
  var hint = document.getElementById('loginHint');
  /* kind: 'info' — що робити далі, 'ok' — вийшло, 'error' — не вийшло */
  var SIGN = { info: 'i', ok: '✓', error: '!' };
  function show(msg, kind){
    if (!hint) return;
    kind = SIGN[kind] ? kind : 'error';
    hint.style.display = 'flex';
    hint.textContent = msg;
    hint.setAttribute('data-sign', SIGN[kind]);
    hint.className = 'auth-note is-' + kind;
    void hint.offsetWidth;
    hint.classList.add('is-new');
  }
  function pollStatus(url){
    var n = 0;
    var t = setInterval(function(){
      if (++n > 60) { clearInterval(t); show('Час вийшов. Спробуйте ще раз.'); return; }
      fetch(base + url).then(r=>r.json()).then(function(d){
        if (d.logged_in) { clearInterval(t); location.reload(); }
      });
    }, 2500);
  }
  function startLogin(startUrl, statusUrl, hintMsg){
    fetch(base + startUrl).then(r=>r.json()).then(function(d){
      if (!d.ok) { show(d.error || 'Недоступно', d.kind); return; }
      window.open(d.url, '_blank');
      show(hintMsg, 'info');
      pollStatus(statusUrl);
    });
  }
  var tg = document.getElementById('tgLoginBtn');
  if (tg) tg.addEventListener('click', function(){ startLogin('/auth/tg/start', '/auth/tg/status', 'У боті натисніть Start, а тоді «Поділитися номером» — сайт увійде автоматично…'); });

  /* Відлік на кнопці «Надіслати ще раз»: пауза тримається на сервері, тут лише видно, скільки лишилось */
  function cooldown(btn, sec, label){
    if (!btn) return;
    var left = sec;
    btn.disabled = true;
    btn.textContent = label + ' (' + left + ')';
    clearInterval(btn._t);
    btn._t = setInterval(function(){
      if (--left > 0) { btn.textContent = label + ' (' + left + ')'; return; }
      clearInterval(btn._t);
      btn.disabled = false;
      btn.textContent = label;
    }, 1000);
  }

  var emailSend = document.getElementById('emailSendBtn');
  var emailResend = document.getElementById('emailResendBtn');
  var emailVer = document.getElementById('emailVerifyBtn');
  function emailStart(resent){
    var fd = new FormData();
    fd.append('_csrf', csrf); fd.append('email', document.getElementById('emailInput').value);
    return fetch(base + '/auth/email/start', {method:'POST', body: fd}).then(r=>r.json()).then(function(d){
      if (!d.ok) {
        show(d.error || 'Помилка', d.kind);
        if (d.retry_after) {
          // «Код уже надіслано»: лист є, і вводити його треба десь — тож поле
          // коду показуємо й тут (інакше після оновлення сторінки чи другого
          // натискання людина бачить лише помилку й не має куди вписати код)
          document.getElementById('emailCodeField').style.display = 'block';
          emailVer.style.display = 'inline-flex';
          if (emailSend) emailSend.style.display = 'none';
          if (emailResend) emailResend.style.display = 'inline';
          var vis = emailResend || emailSend;
          cooldown(vis, d.retry_after, 'Надіслати код ще раз');
          var ci0 = document.getElementById('emailCodeInput'); if (ci0) ci0.focus();
        }
        return;
      }
      show(resent
        ? 'Новий код надіслано. Попередній більше не діє.'
        : 'Код надіслано на пошту. Не бачите листа — перевірте «Спам».', 'ok');
      document.getElementById('emailCodeField').style.display = 'block';
      emailVer.style.display = 'inline-flex';
      if (emailSend) emailSend.style.display = 'none';
      if (emailResend) { emailResend.style.display = 'inline'; cooldown(emailResend, 60, 'Надіслати код ще раз'); }
      var ci = document.getElementById('emailCodeInput'); if (ci) ci.focus();
    });
  }
  if (emailSend) emailSend.addEventListener('click', function(){ emailStart(false); });
  if (emailResend) emailResend.addEventListener('click', function(){ emailStart(true); });
  if (emailVer) emailVer.addEventListener('click', function(){
    var fd = new FormData();
    fd.append('_csrf', csrf); fd.append('code', document.getElementById('emailCodeInput').value);
    fetch(base + '/auth/email/verify', {method:'POST', body: fd}).then(r=>r.json()).then(function(d){
      if (d.logged_in) location.reload(); else show(d.error || 'Невірний код');
    });
  });
  // Enter у формі: натискає ту кнопку, що зараз видно — «Отримати код» чи «Увійти»
  var ef = document.getElementById('emailLoginBox');
  if (ef) ef.addEventListener('keydown', function(e){
    if (e.key !== 'Enter') return;
    e.preventDefault();
    (emailVer && emailVer.style.display !== 'none' ? emailVer : emailSend).click();
  });

  var pb = document.getElementById('phoneLoginBtn');
  if (pb) pb.addEventListener('click', function(){
    var box = document.getElementById('phoneLoginBox');
    box.style.display = box.style.display === 'none' ? 'block' : 'none';
  });
  var sendBtn = document.getElementById('phoneSendBtn');
  var phoneResend = document.getElementById('phoneResendBtn');
  function phoneStart(resent){
    var fd = new FormData();
    fd.append('_csrf', csrf); fd.append('phone', document.getElementById('phoneInput').value);
    return fetch(base + '/auth/phone/start', {method:'POST', body: fd}).then(r=>r.json()).then(function(d){
      if (!d.ok) {
        show(d.error || 'Помилка', d.kind);
        if (d.retry_after) {
          var vis = (phoneResend && phoneResend.style.display !== 'none') ? phoneResend : sendBtn;
          cooldown(vis, d.retry_after, vis === sendBtn ? 'Отримати код' : 'Надіслати ще раз');
        }
        return;
      }
      show((d.where || ('Код надіслано у ' + d.via + '.'))
         + (resent ? ' Попередній більше не діє.' : ' Введіть його нижче.'), 'ok');
      document.getElementById('codeField').style.display = 'block';
      document.getElementById('codeVerifyBtn').style.display = 'inline-flex';
      if (sendBtn) sendBtn.style.display = 'none';
      if (phoneResend) { phoneResend.style.display = 'inline'; cooldown(phoneResend, 60, 'Надіслати ще раз'); }
    });
  }
  if (sendBtn) sendBtn.addEventListener('click', function(){ phoneStart(false); });
  if (phoneResend) phoneResend.addEventListener('click', function(){ phoneStart(true); });
  var verBtn = document.getElementById('codeVerifyBtn');
  if (verBtn) verBtn.addEventListener('click', function(){
    var fd = new FormData();
    fd.append('_csrf', csrf); fd.append('code', document.getElementById('codeInput').value);
    fetch(base + '/auth/phone/verify', {method:'POST', body: fd}).then(r=>r.json()).then(function(d){
      if (d.logged_in) location.reload(); else show(d.error || 'Невірний код');
    });
  });
})();
</script>
