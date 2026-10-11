// Монтаж по расшифровке (как в Descript): фразы субтитров → отрезки для вырезания,
// поиск слов-паразитов внутри фразы по доле символов (пословных таймкодов у распознавания нет).

const SPLIT = /[^\p{L}\p{N}'’-]+/u;

/** Экранирование для регулярного выражения. */
const esc = (s) => String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/** Время слова внутри фразы по позиции символа. */
function spanTime(seg, from, to) {
  const len = Math.max(1, (seg.text || '').length);
  const dur = Math.max(0.05, (seg.end || 0) - (seg.start || 0));
  return { start: seg.start + (dur * from) / len, end: seg.start + (dur * to) / len };
}

/**
 * Найти слова-паразиты в сегментах расшифровки.
 * Возвращает [{start, end, word, text, index}] — index — номер фразы.
 */
export function findFillers(segments, words, { pad = 0.08, minSec = 0.12 } = {}) {
  const list = (words || []).map((w) => String(w).trim().toLowerCase()).filter(Boolean);
  if (!list.length) return [];
  // Длинные («как бы») ищем раньше коротких, чтобы «бы» не срабатывало отдельно
  const re = new RegExp(`(?<![\\p{L}\\p{N}])(${list.sort((a, b) => b.length - a.length).map(esc).join('|')})(?![\\p{L}\\p{N}-])`, 'giu');
  const out = [];
  (segments || []).forEach((seg, index) => {
    const text = String(seg.text || '');
    for (const m of text.matchAll(re)) {
      const from = m.index, to = m.index + m[0].length;
      const t = spanTime({ ...seg, text }, from, to);
      const start = Math.max(seg.start, t.start - pad);
      const end = Math.min(seg.end, t.end + pad);
      if (end - start < minSec) continue;
      out.push({
        start: Math.round(start * 100) / 100, end: Math.round(end * 100) / 100,
        word: m[0].toLowerCase(), index,
        text: (text.slice(Math.max(0, from - 30), from) + '⟦' + m[0] + '⟧' + text.slice(to, to + 30)).trim(),
      });
    }
  });
  return out.sort((a, b) => a.start - b.start);
}

/** Слова фразы — для подсветки в интерфейсе. */
export function words(text) {
  return String(text || '').split(SPLIT).filter(Boolean);
}

/** Отрезки для вырезания: расширение на pad, склейка близких, отсечение по длительности. */
export function cutsFromSelection(items, duration, { pad = 0.05, gap = 0.25 } = {}) {
  const src = (items || [])
    .map((x) => ({ start: Math.max(0, (Number(x.start) || 0) - pad), end: Math.min(duration || Infinity, (Number(x.end) || 0) + pad) }))
    .filter((x) => x.end - x.start >= 0.12)
    .sort((a, b) => a.start - b.start);
  const out = [];
  for (const x of src) {
    const last = out[out.length - 1];
    if (last && x.start <= last.end + gap) last.end = Math.max(last.end, x.end);
    else out.push({ ...x });
  }
  return out.map((x) => ({ start: Math.round(x.start * 100) / 100, end: Math.round(x.end * 100) / 100 }));
}
