<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title }} · TRIS Academy</title>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <style>
        :root{color-scheme:light dark;font:16px/1.45 system-ui,sans-serif;--bg:var(--tg-theme-bg-color,Canvas);--card:var(--tg-theme-secondary-bg-color,Field);--ink:var(--tg-theme-text-color,CanvasText);--hint:var(--tg-theme-hint-color,GrayText);--blue:var(--tg-theme-button-color,#397ab5)}
        *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink)}main{max-width:34rem;margin:auto;padding:16px 16px max(24px,env(safe-area-inset-bottom))}
        h1{font-size:22px;margin:4px 0}h2{font-size:17px;margin:0 0 8px}header{text-align:center;margin-bottom:18px}
        p{margin:6px 0;white-space:pre-wrap;overflow-wrap:anywhere}small,.hint{color:var(--hint);font-size:13px}
        .card{background:var(--card);border-radius:16px;padding:14px;margin:10px 0}
        label{display:block;font-weight:600;margin:10px 0 5px}textarea,input,select,button{font:inherit;border-radius:12px}
        textarea,input,select{width:100%;padding:11px;border:1px solid var(--hint);background:var(--bg);color:var(--ink)}
        textarea{resize:vertical;min-height:76px}button{min-height:44px;border:0;padding:10px 14px;background:var(--blue);color:#fff;font-weight:600;cursor:pointer}
        button.secondary{background:var(--card);color:var(--ink);border:1px solid var(--hint)}button:disabled{opacity:.45}
        .wide{width:100%;margin-top:8px}.actions{display:flex;gap:8px;margin:8px 0}.actions>*{flex:1}
        .choices{display:flex;gap:8px}.choices label{flex:1;text-align:center;padding:9px;border:1px solid var(--hint);border-radius:12px;cursor:pointer}
        .choices input{width:auto;margin:0 5px 0 0}.choices label:has(input:checked[value=approved]){background:#286044;color:white}.choices label:has(input:checked[value=rejected]){background:#713c43;color:white}
        .qty{display:grid;grid-template-columns:48px 1fr 48px;gap:8px}.qty input{text-align:center}.qty button{background:var(--bg);border:1px solid var(--hint);color:var(--ink)}
        .error{color:#c84451}.summary{white-space:pre-wrap;overflow-wrap:anywhere}.good{color:#429967}.bad{color:#d36a74}
        [hidden]{display:none!important}a{color:var(--tg-theme-link-color,#438bc8)}
    </style>
</head>
<body>
<main>
    <header>
        <h1>{{ $decision ? 'Решение сохранено' : $title }}</h1>
        <p class="hint">Заявка №{{ $record->id }} · {{ $record->user?->name ?: trim((string) $record->user?->telegram_first_name.' '.(string) $record->user?->telegram_last_name) ?: 'Сотрудник' }}</p>
    </header>
    @if ($decision)
        <div class="card" role="status">
            <strong>{{ ['approved'=>'Одобрено','rejected'=>'Отказано','partially_approved'=>'Частично одобрено'][$decision->status] ?? $decision->status }}</strong>
            @foreach ($items as $item)
                <p>{{ in_array($item['status'], ['approved','issued']) ? '✅' : (in_array($item['status'], ['rejected','cancelled']) ? '❌' : '🟠') }} {{ $item['label'] }}@if($kind === 'inventory') — {{ $item['approved'] }} из {{ $item['requested'] }}@endif</p>
                @if ($item['reason'])<small>{{ $item['reason'] }}</small>@endif
            @endforeach
            <p>{{ $decision->snapshot['comment'] }}</p>
            <small>Ответ сохранён в Academy. Доставка обрабатывается отдельно.</small>
        </div>
        <button id="close" type="button" class="wide">Закрыть</button>
    @else
        @if (filled($kind === 'inventory' ? $record->comment : $record->reason))
            <div class="card"><small>Комментарий сотрудника</small><p>{{ $kind === 'inventory' ? $record->comment : $record->reason }}</p></div>
        @endif
        <form id="review-form" action="{{ $formAction }}" method="POST">
            @csrf
            <input type="hidden" name="fingerprint" value="{{ $fingerprint }}">
            @foreach ($errors->all() as $error)<p class="error" role="alert">{{ $error }}</p>@endforeach
            <div id="editor">
                <p class="hint" id="progress" aria-live="polite"></p>
                <div class="actions">
                    <button type="button" id="approve-all" class="secondary">Одобрить всё</button>
                    <button type="button" id="reset" class="secondary">Сбросить</button>
                </div>
                @foreach ($items as $item)
                    <div class="card item" data-id="{{ $item['id'] }}" data-label="{{ $item['label'] }}" data-status="{{ $item['status'] }}" data-requested="{{ $item['requested'] }}" data-approved="{{ $item['approved'] }}" data-existing-reason="{{ $item['reason'] }}">
                        <h2>{{ $item['label'] }}</h2>
                        @if ($item['status'] !== 'pending')
                            <p>{{ $item['status'] === 'approved' ? '✅ Одобрено' : ($item['status'] === 'rejected' ? '❌ Отказано' : $item['status']) }}</p>
                            @if ($kind === 'inventory')<p>{{ $item['approved'] }} из {{ $item['requested'] }}</p>@endif
                            @if ($item['reason'])<p>{{ $item['reason'] }}</p>@endif
                        @else
                            @if ($kind === 'inventory')
                                <small>Запрошено: {{ $item['requested'] }}</small>
                                <label for="qty-{{ $item['id'] }}">Согласованное количество</label>
                                <div class="qty">
                                    <button type="button" data-step="-1" aria-label="Уменьшить">−</button>
                                    <input id="qty-{{ $item['id'] }}" class="qty-input" type="number" min="0" max="{{ $item['requested'] }}" step="1" inputmode="numeric" name="items[{{ $item['id'] }}][qty]" value="{{ old('items.'.$item['id'].'.qty') }}" placeholder="—" required>
                                    <button type="button" data-step="1" aria-label="Увеличить">+</button>
                                </div>
                            @else
                                <div class="choices">
                                    <label><input type="radio" name="items[{{ $item['id'] }}][choice]" value="approved" @checked(old('items.'.$item['id'].'.choice') === 'approved') required>✓ Одобрить</label>
                                    <label><input type="radio" name="items[{{ $item['id'] }}][choice]" value="rejected" @checked(old('items.'.$item['id'].'.choice') === 'rejected') required>✕ Отказать</label>
                                </div>
                            @endif
                            <div class="reason-area" hidden>
                                <label for="reason-{{ $item['id'] }}">Причина отказа или ограничения *</label>
                                <textarea class="reason" id="reason-{{ $item['id'] }}" name="items[{{ $item['id'] }}][reason]" maxlength="600" rows="2" placeholder="Объясните причину...">{{ old('items.'.$item['id'].'.reason') }}</textarea>
                            </div>
                        @endif
                    </div>
                @endforeach
                @if ($kind !== 'inventory')
                    <div class="card" id="shared-area" hidden>
                        <label for="shared">Одна причина для всех отклонённых дат</label>
                        <textarea id="shared" maxlength="600" rows="2"></textarea>
                        <button type="button" id="apply-shared" class="secondary wide">Применить к отклонённым</button>
                    </div>
                @endif
                <div class="card" id="comment-area">
                    <label for="comment">{{ $kind === 'inventory' ? 'Получение инвентаря' : 'Общий комментарий (необязательно)' }}</label>
                    <textarea id="comment" name="comment" maxlength="1800" rows="{{ $kind === 'inventory' ? 4 : 2 }}">{{ old('comment', $kind === 'inventory' ? $pickupMessage : '') }}</textarea>
                    @if ($kind === 'inventory')<small>Адрес будет кликабельным в сообщении: <a href="{{ $pickupUrl }}" target="_blank" rel="noopener">{{ $pickupAddress }}</a></small>@endif
                </div>
                <button id="preview-button" type="button" class="wide" disabled>Предпросмотр решения</button>
                <button class="secondary wide cancel" type="button">Отмена</button>
            </div>
            <div id="preview" hidden>
                <div class="card"><small>Сотрудник получит</small><p id="preview-text" class="summary"></p></div>
                <button id="confirm" type="submit" class="wide" disabled>Подтвердить и отправить</button>
                <button id="edit" type="button" class="secondary wide">Изменить решение</button>
            </div>
        </form>
    @endif
</main>
<script>
    const app=window.Telegram?.WebApp;
    app?.ready();
    const adminUrl=@json($resourceUrl),isTelegram=@json($telegram);
    function closePage(){if(isTelegram&&app?.initData)app.close();else location.assign(adminUrl)}
    document.querySelectorAll('.cancel,#close').forEach(b=>b.addEventListener('click',closePage));
    @if ($decision)
        if(isTelegram&&app?.initData)window.setTimeout(()=>app.close(),1000);
    @else
        const inventory=@json($kind==='inventory'),items=[...document.querySelectorAll('.item')],form=document.getElementById('review-form'),editor=document.getElementById('editor'),preview=document.getElementById('preview'),previewBtn=document.getElementById('preview-button'),confirm=document.getElementById('confirm'),comment=document.getElementById('comment'),commentArea=document.getElementById('comment-area'),sharedArea=document.getElementById('shared-area');
        let confirmed=false;
        function state(item){
            if(item.dataset.status!=='pending')return{decided:true,approved:['approved','issued','partially_approved','partially_issued'].includes(item.dataset.status),limited:false,label:item.dataset.label,qty:Number(item.dataset.approved),requested:Number(item.dataset.requested),status:item.dataset.status,reason:item.dataset.existingReason};
            const requested=Number(item.dataset.requested),reason=item.querySelector('.reason');
            let decided,approved,limited,status,qty;
            if(inventory){const input=item.querySelector('.qty-input');qty=input.valueAsNumber;decided=input.value!==''&&Number.isInteger(qty)&&qty>=0&&qty<=requested;approved=decided&&qty>0;limited=decided&&qty<requested;status=qty===0?'rejected':qty===requested?'approved':'partially_approved';}
            else{status=item.querySelector('input[type=radio]:checked')?.value;decided=!!status;approved=status==='approved';limited=status==='rejected';}
            const area=item.querySelector('.reason-area');area.hidden=!limited;reason.disabled=!limited;reason.required=limited;
            return{decided,approved,limited,label:item.dataset.label,qty,requested,status,reason:limited?reason.value.trim():''};
        }
        function update(){
            const states=items.map(state),any=states.some(s=>s.approved);
            if(inventory){commentArea.hidden=!any;comment.disabled=!any;comment.required=any;}
            if(sharedArea)sharedArea.hidden=!states.some(s=>s.limited);
            document.getElementById('progress').textContent='Рассмотрено: '+states.filter(s=>s.decided).length+' из '+states.length;
            previewBtn.disabled=!states.every(s=>s.decided&&(!s.limited||s.reason))||(inventory&&any&&!comment.value.trim());
            confirmed=false;confirm.disabled=true;
            return states;
        }
        form.addEventListener('input',update);form.addEventListener('change',update);
        document.querySelectorAll('[data-step]').forEach(b=>b.addEventListener('click',()=>{const input=b.closest('.item').querySelector('.qty-input');const value=Number.isFinite(input.valueAsNumber)?input.valueAsNumber:0;input.value=Math.min(Number(input.max),Math.max(0,value+Number(b.dataset.step)));update()}));
        document.getElementById('approve-all').addEventListener('click',()=>{items.filter(x=>x.dataset.status==='pending').forEach(item=>{if(inventory)item.querySelector('.qty-input').value=item.dataset.requested;else item.querySelector('input[value=approved]').checked=true});update()});
        document.getElementById('reset').addEventListener('click',()=>{items.filter(x=>x.dataset.status==='pending').forEach(item=>{item.querySelectorAll('input[type=radio]').forEach(x=>x.checked=false);const q=item.querySelector('.qty-input');if(q)q.value='';item.querySelector('.reason').value=''});update()});
        document.getElementById('apply-shared')?.addEventListener('click',()=>{const text=document.getElementById('shared').value.trim();items.forEach(item=>{if(item.dataset.status==='pending'&&item.querySelector('input[value=rejected]:checked'))item.querySelector('.reason').value=text});update()});
        previewBtn.addEventListener('click',()=>{const states=update();if(previewBtn.disabled||!form.reportValidity())return;const all=states.every(s=>['approved','issued'].includes(s.status)),none=states.every(s=>['rejected','cancelled'].includes(s.status));const lines=[all?'✅ Одобрено':none?'❌ Отказано':'🟠 Частично одобрено',''];states.forEach(s=>{const icon=['approved','issued'].includes(s.status)?'✅':['rejected','cancelled'].includes(s.status)?'❌':'🟠';lines.push(icon+' '+s.label+(inventory?' — '+s.qty+' из '+s.requested:''));if(s.reason)lines.push(s.reason)});if(!comment.disabled&&comment.value.trim())lines.push('',comment.value.trim());document.getElementById('preview-text').textContent=lines.join('\n');editor.hidden=true;preview.hidden=false;confirmed=true;confirm.disabled=false;window.scrollTo(0,0)});
        document.getElementById('edit').addEventListener('click',()=>{preview.hidden=true;editor.hidden=false;update()});
        form.addEventListener('submit',event=>{if(!confirmed){event.preventDefault();return}confirm.disabled=true;confirm.textContent='Сохраняем…'});
        update();
    @endif
</script>
</body>
</html>
