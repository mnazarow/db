<script setup>
import { ref, onMounted } from 'vue';
import { get, post } from '../../api.js';
import { useUi } from '../../stores/ui.js';
import { useAuth } from '../../stores/auth.js';
import { fmtBytes, fmtDurationLong, fmtDateTime } from '../../utils/format.js';

const ui = useUi(); const auth = useAuth();
const s = ref(null);
const announce = ref({ title: '', body: '', link: '' });
async function load() { s.value = await get('/api/admin/system'); }
onMounted(load);
async function run(action, label) { try { await post(`/api/admin/system/${action}`, {}); ui.toast(label, { type: 'success' }); } catch (e) { ui.toast(e.message, { type: 'error' }); } }
async function sendAnnounce() { try { const r = await post('/api/admin/system/announce', announce.value); ui.toast(`Рассылка поставлена в очередь: ${r.recipients} получателей. Уведомления появятся у всех в течение минуты`, { type: 'success', timeout: 6000 }); announce.value = { title: '', body: '', link: '' }; } catch (e) { ui.toast(e.message, { type: 'error' }); } }
</script>

<template>
  <div>
    <div class="page-head"><div><h1>Система</h1><div class="sub">Состояние сервера и служебные операции</div></div><div class="actions"><button class="btn sm" @click="load"><Icon name="refresh" :size="16" /> Обновить</button></div></div>
    <div v-if="!s" class="loading-block"><div class="spin"></div></div>
    <template v-else>
      <div class="stat-tiles mb-24">
        <div class="stat-tile"><div class="v" style="font-size:22px">{{ s.version }}</div><div class="l">Версия CorpVideo</div><div class="d">Node {{ s.node }}</div></div>
        <div class="stat-tile"><div class="v" style="font-size:22px">{{ fmtBytes(s.disk.free) }}</div><div class="l">Свободно на диске</div><div class="d">из {{ fmtBytes(s.disk.total) }} • медиа {{ fmtBytes(s.mediaBytes) }}</div></div>
        <div class="stat-tile"><div class="v" style="font-size:22px">{{ fmtBytes(s.dbSize) }}</div><div class="l">База данных</div></div>
        <div class="stat-tile"><div class="v" style="font-size:22px">{{ fmtDurationLong(s.uptime) }}</div><div class="l">Аптайм API</div><div class="d">RSS {{ fmtBytes(s.memory) }} • нагрузка {{ s.load.map((x) => x.toFixed(2)).join(' / ') }}</div></div>
        <div class="stat-tile"><div class="v" style="font-size:22px">{{ s.ws.sockets }}</div><div class="l">WebSocket-подключений</div><div class="d">{{ s.ws.users }} пользователей онлайн</div></div>
      </div>
      <div class="two-col mb-24">
        <div class="panel"><h3 class="mb-16">Компоненты</h3>
          <div class="table-scroll"><table class="table"><tbody>
            <tr><td>PostgreSQL</td><td class="small">{{ s.postgres.split(',')[0] }}</td></tr>
            <tr><td>ffmpeg</td><td class="small">{{ s.ffmpeg || 'не найден' }} <span v-if="s.encoders.nvenc" class="badge">NVENC</span> <span v-if="s.encoders.vaapi" class="badge">VAAPI</span> <span v-if="s.encoders.qsv" class="badge">QSV</span></td></tr>
            <tr><td>tesseract (OCR)</td><td class="small">{{ s.ocr?.available ? `${s.ocr.version} · языки: ${s.ocr.languages.join(', ')}` : 'не установлен (текст на экране недоступен)' }}</td></tr>
            <tr><td>MediaMTX (трансляции)</td><td><span class="badge" :class="s.mediamtx ? 'success' : 'danger'">{{ s.mediamtx ? 'доступен' : 'недоступен' }}</span></td></tr>
            <tr><td>Почта</td><td><span class="badge" :class="s.emailEnabled ? 'success' : ''">{{ s.emailEnabled ? 'настроена' : 'не настроена' }}</span></td></tr>
            <tr><td>Воркеры (активные задания)</td><td class="small"><div v-for="w in s.workersRunning" :key="w.locked_by">{{ w.locked_by }}: {{ w.n }}</div><div v-for="w in s.workersRecent" :key="'r' + w.locked_by" class="muted">{{ w.locked_by }} — последнее задание {{ fmtDateTime(w.last) }}</div><span v-if="!s.workersRunning.length && !s.workersRecent.length" class="muted">нет данных за сутки</span></td></tr>
            <tr><td>Каталог данных</td><td class="mono small">{{ s.dataDir }}</td></tr>
            <tr><td>Адрес портала</td><td class="mono small">{{ s.baseUrl }}</td></tr>
            <tr><td>Сервер</td><td class="small">{{ s.hostname }} • {{ s.platform }} • {{ s.cpus }} CPU</td></tr>
          </tbody></table></div>
        </div>
        <div class="col gap-24">
          <div class="panel"><h3 class="mb-16">Служебные операции</h3>
            <div class="col gap-8">
              <button class="btn sm" @click="run('recompute-storage', 'Пересчёт поставлен в очередь')"><Icon name="harddisk" :size="16" /> Пересчитать занятое место</button>
              <button class="btn sm" @click="run('reindex', 'Поисковый индекс обновлён')"><Icon name="textSearch" :size="16" /> Перестроить поисковый индекс</button>
              <button class="btn sm" @click="run('maintenance', 'Обслуживание запущено')"><Icon name="wrench" :size="16" /> Запустить обслуживание (очистка, счётчики)</button>
            </div>
          </div>
          <div class="panel" v-if="auth.isAdmin"><h3 class="mb-8">Объявление всем пользователям</h3><p class="small muted">Уведомление в колокольчик каждому активному пользователю.</p>
            <div class="col gap-8"><input class="input" v-model="announce.title" placeholder="Заголовок" /><textarea class="textarea" v-model="announce.body" placeholder="Текст" style="min-height:72px"></textarea><input class="input" v-model="announce.link" placeholder="Ссылка (необязательно)" /><button class="btn primary sm" :disabled="!announce.title" @click="sendAnnounce"><Icon name="campaign" :size="16" /> Отправить</button></div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
