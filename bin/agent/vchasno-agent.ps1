<#
    Агент каси: приносить фіскальні завдання з сайту на ПРРО цієї точки.

    Навіщо він є. Ключ підпису лежить у магазині — на флешці або в папці, — і
    касу (Device Manager) навмисно не видно з інтернету. Тому не сайт стукає до
    каси, а агент стукає до сайту: назовні в магазині не відкрито жодного порту,
    а телефон продавця може працювати хоч із дому, бо він говорить лише з сайтом.

    Що робить: раз на кілька секунд питає «є що пробити?», несе отримане тіло
    запиту на localhost:3939 і повертає відповідь як є. Ні про формат чека, ні
    про постачальника ПРРО він не знає — і не має: інакше кожну зміну в чеку
    довелося б розвозити по всіх касових ПК мережі.

    Обірваний звʼязок не страшний: у кожного завдання є незмінна мітка (tag), і
    повтор із нею каса впізнає як ту саму спробу й віддасть той самий чек.
    Тому агента можна перезапускати будь-коли й скільки завгодно разів.

    Запуск:
        powershell -ExecutionPolicy Bypass -File vchasno-agent.ps1

    Налаштування — у файлі agent.config.json поруч зі скриптом:
        {
          "Site":  "https://ваш-сайт.ua",
          "Token": "ag_....",           // Магазини -> картка точки -> «Показати токен»
          "Dm":    "http://localhost:3939"
        }

    Щоб працював постійно: Планувальник завдань Windows -> створити завдання ->
    «Виконувати незалежно від того, чи ввійшов користувач», тригер «При запуску
    комп'ютера», дія — рядок запуску вище.
#>

param(
    [string]$Site = "",
    [string]$Token = "",
    [string]$Dm = "",
    [int]$Interval = 3,
    [switch]$Once
)

$ErrorActionPreference = "Stop"
# Старі збірки Windows за замовчуванням пробують TLS 1.0, який сучасний хостинг
# уже не приймає, — і агент мовчки не міг би достукатись до сайту
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$configPath = Join-Path $here "agent.config.json"
if (Test-Path $configPath) {
    $cfg = Get-Content $configPath -Raw -Encoding UTF8 | ConvertFrom-Json
    if (-not $Site  -and $cfg.Site)  { $Site  = $cfg.Site }
    if (-not $Token -and $cfg.Token) { $Token = $cfg.Token }
    if (-not $Dm    -and $cfg.Dm)    { $Dm    = $cfg.Dm }
    if ($cfg.Interval) { $Interval = [int]$cfg.Interval }
}
if (-not $Site -or -not $Token) {
    Write-Host "Немає адреси сайту або токена." -ForegroundColor Red
    Write-Host "Заповніть agent.config.json поруч зі скриптом (див. коментар угорі)."
    exit 1
}
$Site = $Site.TrimEnd('/')

$logFile = Join-Path $here "agent.log"
function Write-Log($text, $color = "Gray") {
    $line = "{0}  {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $text
    Write-Host $line -ForegroundColor $color
    try { Add-Content -Path $logFile -Value $line -Encoding UTF8 } catch {}
}

Write-Log "Агент запущено. Сайт: $Site" "Cyan"
if ($Dm) { Write-Log "Каса: $Dm (з налаштувань агента)" "Cyan" }

# Скільки чекати після невдалої спроби. Росте до хвилини, щоб при довгій
# відсутності мережі не молотити щосекунди й не засмічувати журнал.
$backoff = $Interval

while ($true) {
    try {
        $pull = Invoke-RestMethod -Method Post -Uri "$Site/api/fiscal/pull" `
            -ContentType "application/json; charset=utf-8" `
            -Body ([Text.Encoding]::UTF8.GetBytes((@{ token = $Token; limit = 5 } | ConvertTo-Json))) `
            -TimeoutSec 20

        if (-not $pull.ok) {
            Write-Log "Сайт відмовив: $($pull.error)" "Red"
            Start-Sleep -Seconds ([Math]::Min($backoff * 2, 60)); $backoff = [Math]::Min($backoff * 2, 60)
            continue
        }
        $backoff = $Interval

        foreach ($job in $pull.jobs) {
            # Адресу каси бере з завдання, але місцеве налаштування важливіше:
            # на самому ПК зручніше localhost, хоч би що стояло в адмінці
            $url = if ($Dm) { "$($Dm.TrimEnd('/'))/dm/execute" } else { $job.url }
            $bodyJson = $job.body | ConvertTo-Json -Depth 12 -Compress
            Write-Log "Завдання $($job.id) -> $url"

            $answer = $null
            try {
                $answer = Invoke-RestMethod -Method Post -Uri $url `
                    -ContentType "application/json; charset=utf-8" `
                    -Body ([Text.Encoding]::UTF8.GetBytes($bodyJson)) `
                    -TimeoutSec $job.timeout
            } catch {
                # Каса не відповіла. Порожню відповідь віддаємо навмисно: сайт
                # позначить чек непевним і поверне завдання в чергу. Пробити
                # його другий раз не вийде — мітка та сама.
                Write-Log "Каса не відповіла: $($_.Exception.Message)" "Yellow"
            }

            $payload = @{ token = $Token; id = $job.id; response = $answer }
            $push = Invoke-RestMethod -Method Post -Uri "$Site/api/fiscal/push" `
                -ContentType "application/json; charset=utf-8" `
                -Body ([Text.Encoding]::UTF8.GetBytes(($payload | ConvertTo-Json -Depth 12))) `
                -TimeoutSec 20

            if ($push.state -eq "done") { Write-Log "Завдання $($job.id): чек пробито" "Green" }
            elseif ($push.state -eq "error") { Write-Log "Завдання $($job.id): каса відмовила — $($push.error)" "Red" }
            else { Write-Log "Завдання $($job.id): відповіді немає, спробуємо ще" "Yellow" }
        }
    } catch {
        Write-Log "Збій: $($_.Exception.Message)" "Red"
        Start-Sleep -Seconds ([Math]::Min($backoff * 2, 60)); $backoff = [Math]::Min($backoff * 2, 60)
        continue
    }

    if ($Once) { break }
    Start-Sleep -Seconds $Interval
}
