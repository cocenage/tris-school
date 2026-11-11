<?php

namespace App\Services\Telegram\HtmlBubbles;

use Carbon\CarbonImmutable;

class OksWidgetBuilder
{
    public function html(): string
    {
        $date = e(CarbonImmutable::now('Europe/Rome')->format('d.m.Y'));

        return <<<HTML
<!doctype html>
<html lang="ru">
<head>
<style>
:root{color-scheme:light dark;--bg:#fff;--fg:#19202a;--muted:#667085;--line:#e5e7eb;--accent:#2563eb;--soft:#f3f6fb}
*{box-sizing:border-box}body{margin:0;padding:12px;background:var(--bg);color:var(--fg);font:14px/1.35 system-ui,sans-serif}
main{width:100%;max-width:600px;margin:0 auto}h1{font-size:19px;line-height:1.2;margin:0 0 4px;font-weight:700}p{margin:0}
.date{color:var(--muted);margin-bottom:10px}.demo{font-size:11px;letter-spacing:.04em;color:var(--accent);font-weight:700;margin-bottom:8px}
.districts{display:grid;gap:6px}.district{display:flex;align-items:center;justify-content:space-between;gap:8px;border:1px solid var(--line);border-radius:9px;padding:8px 10px;min-height:38px}
.district strong{font-size:14px}.unknown{color:var(--muted);font-size:12px;text-align:right}.total{margin:9px 0;padding:9px 10px;background:var(--soft);border-radius:9px;font-weight:600}
.notice{min-height:34px;color:var(--muted);font-size:12px;margin:5px 0 8px}.actions{display:grid;grid-template-columns:1fr 1fr;gap:7px}
button{min-height:40px;padding:8px;border:0;border-radius:9px;background:var(--accent);color:#fff;font:600 12px/1.2 system-ui,sans-serif;cursor:pointer}
button.secondary{background:var(--soft);color:var(--fg);border:1px solid var(--line)}
@media(max-width:360px){body{padding:9px}.actions{grid-template-columns:1fr}h1{font-size:18px}}
</style>
</head>
<body>
<main aria-live="polite">
<header><h1>☀️ TRIS · ОКС</h1><p class="date">Рабочий день · {$date}</p><p class="demo">ДЕМО-РЕЖИМ · ИСТОЧНИКИ НЕ ПОДКЛЮЧЕНЫ</p></header>
<section class="districts" aria-label="Районы">
<div class="district"><strong>Lambrate</strong><span class="unknown">Нет подтверждённых данных</span></div>
<div class="district"><strong>Lodi</strong><span class="unknown">Нет подтверждённых данных</span></div>
<div class="district"><strong>Certosa</strong><span class="unknown">Нет подтверждённых данных</span></div>
<div class="district"><strong>Navigli</strong><span class="unknown">Нет подтверждённых данных</span></div>
<div class="district"><strong>Como</strong><span class="unknown">Нет подтверждённых данных</span></div>
</section>
<p class="total">Уборки и дежурные: данные не подключены</p>
<p class="notice" id="notice" role="status">Эта карточка не создаёт реальные контролы и не хранит ответы.</p>
<div class="actions"><button id="check" type="button">Проверить доступ</button><button id="update" class="secondary" type="button">Обновить тестовый статус</button></div>
</main>
<script>
(function(){
var api=window.TelegramWidget;
var notice=document.getElementById('notice');
var statusText=document.querySelector('.demo');
function stateFrom(value){try{return JSON.parse(value||'{}')}catch(e){return {}}}
function renderState(){var state=stateFrom(api&&api.chatData);statusText.textContent=state.status==='complete'?'ДЕМО-РЕЖИМ · ОКС · ТЕСТ ПРОЙДЕН':'ДЕМО-РЕЖИМ · ИСТОЧНИКИ НЕ ПОДКЛЮЧЕНЫ'}
function theme(){if(!api||!api.themeParams)return;var p=api.themeParams;var s=document.documentElement.style;s.setProperty('--bg',p.bg_color||'#fff');s.setProperty('--fg',p.text_color||'#19202a');s.setProperty('--muted',p.hint_color||'#667085');s.setProperty('--line',p.section_separator_color||'#e5e7eb');s.setProperty('--accent',p.button_color||'#2563eb');s.setProperty('--soft',p.secondary_bg_color||'#f3f6fb')}
function ask(action){if(!api||typeof api.request!=='function'){notice.textContent='Действия доступны только в совместимой версии Telegram.';return}api.request(action).then(function(answer){if(answer&&answer.result)notice.textContent=answer.result;renderState()}).catch(function(){notice.textContent='Не удалось получить ответ. Повторите нажатие позже.'})}
document.getElementById('check').addEventListener('click',function(){ask('access:check')});
document.getElementById('update').addEventListener('click',function(){ask('demo:complete')});
if(api&&typeof api.onEvent==='function'){theme();renderState();api.onEvent('themeChanged',theme);api.onEvent('chatDataChanged',renderState)}
})();
</script>
</body>
</html>
HTML;
    }

    public function caption(): string
    {
        return 'ОКС · Демо: список сменных назначений и количество уборок не подключены. Карточка доступна в совместимых версиях Telegram.';
    }

    /** @return array{version: int, status: string} */
    public function initialChatData(): array
    {
        return ['version' => 1, 'status' => 'prepared'];
    }
}
