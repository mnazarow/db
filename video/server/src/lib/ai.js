// Обогащение видео с помощью LLM (OpenAI-совместимый /v1/chat/completions: Ollama, LM Studio, vLLM, OpenAI и т.п.).
import { getSettings } from './settings.js';
import { safeFetch, responseHint, POLICY } from './net.js';

async function chat(s, messages, { json = true, timeoutMs } = {}) {
  const url = s['ai.url'];
  if (!url) throw new Error('Не задан адрес сервера ИИ');
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), timeoutMs || (Number(s['ai.timeout_sec']) || 300) * 1000);
  try {
    const body = { model: s['ai.model'] || 'default', messages, temperature: 0.2, stream: false };
    if (json) body.response_format = { type: 'json_object' };
    let res = await safeFetch(url, { method: 'POST', headers: { 'content-type': 'application/json', ...(s['ai.api_key'] ? { authorization: `Bearer ${s['ai.api_key']}` } : {}) }, body: JSON.stringify(body), signal: ctrl.signal }, POLICY.integration);
    if (!res.ok && json && (res.status === 400 || res.status === 422)) {
      // сервер не поддерживает response_format — повторяем без него
      delete body.response_format;
      res = await safeFetch(url, { method: 'POST', headers: { 'content-type': 'application/json', ...(s['ai.api_key'] ? { authorization: `Bearer ${s['ai.api_key']}` } : {}) }, body: JSON.stringify(body), signal: ctrl.signal }, POLICY.integration);
    }
    if (!res.ok) { const hint = responseHint(res, await res.text().catch(() => '')); throw new Error(`Сервер ИИ ответил ${res.status}${hint ? `: ${hint}` : ''}`); }
    const data = await res.json();
    const text = data.choices?.[0]?.message?.content ?? '';
    return String(text);
  } finally { clearTimeout(timer); }
}

function extractJson(text) {
  const t = String(text).trim();
  try { return JSON.parse(t); } catch { /* ниже */ }
  const m = t.match(/```(?:json)?\s*([\s\S]*?)```/) || t.match(/(\{[\s\S]*\})/);
  if (m) { try { return JSON.parse(m[1]); } catch { /* ignore */ } }
  throw new Error('Модель вернула не JSON: ' + t.slice(0, 200));
}

const fmt = (sec) => { const s = Math.max(0, Math.round(sec)); const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = s % 60; return (h ? `${h}:${String(m).padStart(2, '0')}` : `${m}`) + ':' + String(x).padStart(2, '0'); };

/** Прямой вызов модели: messages → JSON (или текст при json=false). Используется ИИ-поиском по видеотеке. */
export async function askAi(messages, { settings = null, json = true, timeoutMs } = {}) {
  const s = settings || await getSettings('ai.');
  if (!s['ai.enabled']) throw new Error('ИИ-помощник отключён в настройках');
  const text = await chat(s, messages, { json, timeoutMs });
  return json ? extractJson(text) : text;
}

/**
 * Предложения по транскрипту: название, описание (конспект), главы, теги.
 * transcript — массив {start,end,text} (сегменты субтитров) или строка.
 */
export async function enrichVideo({ title, transcript, duration, fields = ['title', 'summary', 'chapters', 'tags'] }, settings = null) {
  const s = settings || await getSettings('ai.');
  if (!s['ai.enabled'] && !settings) throw new Error('ИИ отключён в настройках');
  const maxChars = Number(s['ai.max_chars']) || 24000;
  let text = '';
  if (Array.isArray(transcript)) {
    // равномерно прореживаем сегменты, чтобы уложиться в лимит, сохраняя таймкоды
    const total = transcript.reduce((n, c) => n + c.text.length + 12, 0);
    const keepEvery = total > maxChars ? Math.ceil(total / maxChars) : 1;
    text = transcript.filter((_, i) => i % keepEvery === 0).map((c) => `[${fmt(c.start)}] ${c.text}`).join('\n');
  } else text = String(transcript || '').slice(0, maxChars);
  if (!text.trim()) throw new Error('Нет транскрипта — сначала создайте субтитры');
  const want = [];
  if (fields.includes('title')) want.push('"title": короткое информативное название (до 80 символов)');
  if (fields.includes('summary')) want.push('"summary": описание-конспект на 3–6 предложений и список ключевых пунктов (с переносами строк, без markdown-заголовков)');
  if (fields.includes('chapters')) want.push('"chapters": массив глав [{"start": секунды (число), "title": название главы}] — 3–12 глав по смысловым частям, первая с start 0, только по таймкодам из транскрипта');
  if (fields.includes('tags')) want.push('"tags": массив из 3–8 коротких тегов на русском в нижнем регистре');
  const system = 'Ты помощник корпоративного видеопортала. Отвечай строго одним JSON-объектом на русском языке без пояснений.';
  const user = `Видео «${title}», длительность ${fmt(duration || 0)}. Ниже транскрипт с таймкодами [м:сс].\n\nВерни JSON с полями:\n${want.join(';\n')}.\n\nТранскрипт:\n${text}`;
  const raw = await chat(s, [{ role: 'system', content: system }, { role: 'user', content: user }]);
  const j = extractJson(raw);
  const out = {};
  if (fields.includes('title') && j.title) out.title = String(j.title).trim().slice(0, 150);
  if (fields.includes('summary') && j.summary) out.summary = (Array.isArray(j.summary) ? j.summary.join('\n') : String(j.summary)).trim().slice(0, 5000);
  if (fields.includes('chapters') && Array.isArray(j.chapters)) {
    out.chapters = j.chapters.map((c) => ({ start: Math.max(0, Math.round(Number(c.start) || 0)), title: String(c.title || '').trim().slice(0, 120) }))
      .filter((c) => c.title && (!duration || c.start < duration)).sort((a, b) => a.start - b.start)
      .filter((c, i, arr) => i === 0 || c.start > arr[i - 1].start).slice(0, 30);
    if (out.chapters.length && out.chapters[0].start !== 0) out.chapters[0].start = 0;
  }
  if (fields.includes('tags') && Array.isArray(j.tags)) out.tags = [...new Set(j.tags.map((t) => String(t).trim().toLowerCase().replace(/^#/, '').slice(0, 40)).filter(Boolean))].slice(0, 12);
  return out;
}

/** Проверка доступности сервера ИИ из панели администратора. */
export async function aiTest(settings) {
  const raw = await chat(settings, [{ role: 'user', content: 'Ответь одним словом: готов' }], { json: false, timeoutMs: 30000 });
  return { ok: true, answer: raw.slice(0, 100) };
}

/**
 * Перевод строк субтитров пакетом: возвращает массив переводов той же длины и порядка.
 * Модель получает нумерованный список и обязана вернуть JSON {"lines": ["…", …]}.
 */
export async function translateLines(lines, { from = 'ru', to = 'en', toName = 'English', context = '' } = {}, settings = null) {
  const s = settings || await getSettings('ai.');
  if (!lines.length) return [];
  const numbered = lines.map((l, i) => `${i + 1}. ${String(l).replace(/\s+/g, ' ').trim()}`).join('\n');
  const system = `You are a professional subtitle translator. Translate each numbered line from ${from} into ${toName} (${to}). Keep the number of lines exactly the same, keep the order, do not merge or split lines, keep names, numbers and terminology consistent, keep lines short as subtitles. Answer strictly with a JSON object {"lines": ["translation of line 1", "translation of line 2", ...]} without any comments.`;
  const user = `${context ? `Video title: ${context}\n\n` : ''}Lines:\n${numbered}`;
  const raw = await chat(s, [{ role: 'system', content: system }, { role: 'user', content: user }]);
  const j = extractJson(raw);
  let out = Array.isArray(j) ? j : (Array.isArray(j.lines) ? j.lines : null);
  if (!out) throw new Error('Модель вернула ответ без списка строк');
  out = out.map((x) => (typeof x === 'string' ? x : (x && typeof x === 'object' ? String(x.text ?? x.translation ?? '') : String(x ?? ''))).replace(/^\d+[.)]\s*/, '').trim());
  if (out.length !== lines.length) {
    // мягкое восстановление: подравниваем длину
    if (out.length > lines.length) out = out.slice(0, lines.length);
    while (out.length < lines.length) out.push(lines[out.length]);
  }
  return out;
}
