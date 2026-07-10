<?php require_once __DIR__ . '/src/UserFieldMigrator.php'; ?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Перенос полей Битрикс24</title>
<style>
    :root {
        --bg: #f4f6f8; --card: #ffffff; --border: #dde3ea; --text: #1f2733;
        --muted: #6b7685; --accent: #2067b0; --accent-h: #17527e;
        --ok: #1d8348; --warn: #b9770e; --err: #c0392b;
    }
    * { box-sizing: border-box; }
    body { margin: 0; font: 15px/1.5 "Segoe UI", system-ui, sans-serif; background: var(--bg); color: var(--text); }
    .wrap { max-width: 1100px; margin: 0 auto; padding: 24px 16px 60px; }
    h1 { font-size: 22px; margin: 0 0 4px; }
    .subtitle { color: var(--muted); margin: 0 0 24px; }
    .card { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 20px; margin-bottom: 20px; }
    .card h2 { font-size: 16px; margin: 0 0 14px; }
    label { display: block; font-size: 13px; color: var(--muted); margin-bottom: 4px; }
    input[type=text], select {
        width: 100%; padding: 9px 12px; border: 1px solid var(--border); border-radius: 7px;
        font: inherit; background: #fff; color: var(--text);
    }
    input[type=text]:focus, select:focus { outline: 2px solid var(--accent); outline-offset: -1px; border-color: transparent; }
    .row { display: flex; gap: 16px; flex-wrap: wrap; }
    .row > div { flex: 1 1 320px; }
    .hook-line { display: flex; gap: 8px; align-items: flex-end; }
    .hook-line > div:first-child { flex: 1; }
    button {
        padding: 9px 18px; border: 0; border-radius: 7px; font: inherit; cursor: pointer;
        background: var(--accent); color: #fff; white-space: nowrap;
    }
    button:hover { background: var(--accent-h); }
    button.secondary { background: #e7ecf1; color: var(--text); }
    button.secondary:hover { background: #d8e0e8; }
    button:disabled { opacity: .5; cursor: default; }
    .toolbar { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 14px; }
    .toolbar .grow { flex: 1; }
    table { width: 100%; border-collapse: collapse; font-size: 14px; }
    th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--border); }
    th { color: var(--muted); font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; }
    tr:hover td { background: #f7fafc; }
    .badge { display: inline-block; padding: 2px 9px; border-radius: 99px; font-size: 12px; font-weight: 600; }
    .badge.exists  { background: #e8f6ee; color: var(--ok); }
    .badge.missing { background: #fdeee8; color: var(--err); }
    .badge.type    { background: #eef2f7; color: var(--muted); font-weight: 500; }
    .status { font-size: 13px; margin-left: 10px; }
    .status.ok { color: var(--ok); } .status.err { color: var(--err); }
    .checkbox-line { display: flex; align-items: center; gap: 8px; font-size: 14px; color: var(--text); }
    #log { background: #10161d; color: #d6e0ea; border-radius: 8px; padding: 14px 16px; font: 13px/1.6 Consolas, monospace; max-height: 340px; overflow: auto; white-space: pre-wrap; display: none; }
    #log .ok { color: #6fcf97; } #log .warn { color: #f2c94c; } #log .err { color: #eb7a6d; }
    .hint { font-size: 13px; color: var(--muted); margin-top: 10px; }
    .empty { color: var(--muted); padding: 24px; text-align: center; }
</style>
</head>
<body>
<div class="wrap">
    <h1>Перенос пользовательских полей Битрикс24</h1>
    <p class="subtitle">Копирование полей UF_CRM_* сделок, лидов, контактов, компаний и предложений с одного портала на другой</p>

    <div class="card">
        <h2>1. Подключение порталов</h2>
        <div class="row">
            <div>
                <div class="hook-line">
                    <div>
                        <label for="sourceWebhook">Вебхук портала-источника (откуда переносим)</label>
                        <input type="text" id="sourceWebhook" placeholder="https://source.bitrix24.ru/rest/1/xxxxxxxx/">
                    </div>
                    <button class="secondary" onclick="testConnection('source')">Проверить</button>
                </div>
                <span class="status" id="sourceStatus"></span>
            </div>
            <div>
                <div class="hook-line">
                    <div>
                        <label for="targetWebhook">Вебхук портала-приёмника (куда переносим)</label>
                        <input type="text" id="targetWebhook" placeholder="https://target.bitrix24.ru/rest/1/xxxxxxxx/">
                    </div>
                    <button class="secondary" onclick="testConnection('target')">Проверить</button>
                </div>
                <span class="status" id="targetStatus"></span>
            </div>
        </div>
        <div style="margin-top:14px">
            <button onclick="saveConfig()">Сохранить настройки</button>
            <span class="status" id="saveStatus"></span>
        </div>
        <p class="hint">
            Входящий вебхук создаётся на портале: Разработчикам &rarr; Другое &rarr; Входящий вебхук.
            Права: <b>CRM (crm)</b>. Для источника достаточно чтения, для приёмника нужна запись.
        </p>
    </div>

    <div class="card">
        <h2>2. Выбор полей</h2>
        <div class="toolbar">
            <div>
                <label for="entity">Сущность CRM</label>
                <select id="entity">
                    <?php foreach (UserFieldMigrator::ENTITIES as $code => $info): ?>
                    <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($info['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button onclick="loadFields()" id="loadBtn">Загрузить поля</button>
            <div class="grow"></div>
            <span class="status" id="loadStatus"></span>
        </div>
        <div id="fieldsBox">
            <div class="empty">Выберите сущность и нажмите «Загрузить поля»</div>
        </div>
    </div>

    <div class="card">
        <h2>3. Перенос</h2>
        <div class="toolbar">
            <label class="checkbox-line" style="margin:0">
                <input type="checkbox" id="updateExisting">
                Обновлять свойства полей, которые уже есть на приёмнике
            </label>
            <div class="grow"></div>
            <button onclick="migrate()" id="migrateBtn" disabled>Перенести выбранные поля</button>
        </div>
        <div id="log"></div>
    </div>
</div>

<script>
let fields = [];

async function api(action, payload = {}) {
    const res = await fetch('api.php?action=' + action, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload),
    });
    const data = await res.json().catch(() => ({ok: false, error: 'Некорректный ответ сервера'}));
    if (!data.ok) throw new Error(data.error || 'Неизвестная ошибка');
    return data;
}

function setStatus(id, text, cls) {
    const el = document.getElementById(id);
    el.textContent = text;
    el.className = 'status ' + (cls || '');
}

async function loadInitialConfig() {
    try {
        const data = await api('get_config');
        document.getElementById('sourceWebhook').value = data.config.sourceWebhook;
        document.getElementById('targetWebhook').value = data.config.targetWebhook;
    } catch (e) { /* первый запуск — конфига ещё нет */ }
}

async function saveConfig() {
    setStatus('saveStatus', 'Сохранение…');
    try {
        await api('save_config', {
            sourceWebhook: document.getElementById('sourceWebhook').value,
            targetWebhook: document.getElementById('targetWebhook').value,
        });
        setStatus('saveStatus', 'Сохранено', 'ok');
    } catch (e) {
        setStatus('saveStatus', e.message, 'err');
    }
}

async function testConnection(which) {
    const statusId = which + 'Status';
    setStatus(statusId, 'Проверка…');
    try {
        await saveConfig();
        const data = await api('test_connection', {which});
        setStatus(statusId, 'OK: ' + data.user, 'ok');
    } catch (e) {
        setStatus(statusId, e.message, 'err');
    }
}

async function loadFields() {
    const btn = document.getElementById('loadBtn');
    btn.disabled = true;
    setStatus('loadStatus', 'Загрузка полей с обоих порталов…');
    try {
        const data = await api('load_fields', {entity: document.getElementById('entity').value});
        fields = data.fields;
        renderFields();
        setStatus('loadStatus', 'Полей на источнике: ' + fields.length + ', на приёмнике: ' + data.targetCount, 'ok');
    } catch (e) {
        setStatus('loadStatus', e.message, 'err');
    } finally {
        btn.disabled = false;
    }
}

function renderFields() {
    const box = document.getElementById('fieldsBox');
    if (!fields.length) {
        box.innerHTML = '<div class="empty">На портале-источнике нет пользовательских полей для этой сущности</div>';
        document.getElementById('migrateBtn').disabled = true;
        return;
    }
    let html = '<table><thead><tr>' +
        '<th><input type="checkbox" id="checkAll" onchange="toggleAll(this.checked)"></th>' +
        '<th>Название</th><th>Код поля</th><th>Тип</th><th>На приёмнике</th>' +
        '</tr></thead><tbody>';
    for (const f of fields) {
        const flags = [f.multiple ? 'множ.' : '', f.mandatory ? 'обяз.' : ''].filter(Boolean).join(', ');
        html += '<tr>' +
            '<td><input type="checkbox" class="fieldCheck" value="' + escapeHtml(f.fieldName) + '"' +
                (f.existsOnTarget ? '' : ' checked') + '></td>' +
            '<td>' + escapeHtml(f.label) + (flags ? ' <span class="badge type">' + flags + '</span>' : '') + '</td>' +
            '<td><code>' + escapeHtml(f.fieldName) + '</code></td>' +
            '<td><span class="badge type">' + escapeHtml(f.type) + '</span>' +
                (f.portalBound ? ' <span class="badge missing" title="Тип поля ссылается на объекты портала — настройки на приёмнике нужно проверить">!</span>' : '') + '</td>' +
            '<td>' + (f.existsOnTarget
                ? '<span class="badge exists">есть</span>'
                : '<span class="badge missing">нет</span>') + '</td>' +
            '</tr>';
    }
    box.innerHTML = html + '</tbody></table>';
    document.getElementById('migrateBtn').disabled = false;
}

function toggleAll(checked) {
    document.querySelectorAll('.fieldCheck').forEach(cb => cb.checked = checked);
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function logLine(text, cls) {
    const log = document.getElementById('log');
    log.style.display = 'block';
    const span = document.createElement('span');
    if (cls) span.className = cls;
    span.textContent = text + '\n';
    log.appendChild(span);
    log.scrollTop = log.scrollHeight;
}

async function migrate() {
    const selected = [...document.querySelectorAll('.fieldCheck:checked')].map(cb => cb.value);
    if (!selected.length) { alert('Отметьте хотя бы одно поле'); return; }

    const btn = document.getElementById('migrateBtn');
    btn.disabled = true;
    document.getElementById('log').innerHTML = '';
    logLine('Перенос ' + selected.length + ' пол(я/ей)…');

    try {
        // Переносим пачками по 5, чтобы видеть прогресс и не упираться в таймауты
        const chunkSize = 5;
        for (let i = 0; i < selected.length; i += chunkSize) {
            const chunk = selected.slice(i, i + chunkSize);
            const data = await api('migrate', {
                entity: document.getElementById('entity').value,
                fields: chunk,
                updateExisting: document.getElementById('updateExisting').checked,
            });
            for (const r of data.results) {
                const cls = r.status === 'created' ? 'ok' : (r.status === 'error' ? 'err' : 'warn');
                logLine('[' + r.status.toUpperCase() + '] ' + r.fieldName + ' — ' + r.message, cls);
            }
        }
        logLine('Готово. Обновляю список полей…', 'ok');
        await loadFields();
    } catch (e) {
        logLine('Ошибка: ' + e.message, 'err');
    } finally {
        btn.disabled = false;
    }
}

loadInitialConfig();
</script>
</body>
</html>
