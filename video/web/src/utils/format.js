// Форматирование: длительность, числа, даты, размеры.
export function fmtDuration(sec) {
  sec = Math.max(0, Math.round(Number(sec) || 0));
  const h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
  return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}` : `${m}:${String(s).padStart(2, '0')}`;
}

export function fmtDurationLong(sec) {
  sec = Math.round(Number(sec) || 0);
  const h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60);
  if (h > 0) return `${h} ч ${m} мин`;
  if (m > 0) return `${m} мин`;
  return `${sec} с`;
}

function plural(n, one, few, many) {
  const a = Math.abs(n) % 100, b = a % 10;
  if (a > 10 && a < 20) return many;
  if (b > 1 && b < 5) return few;
  if (b === 1) return one;
  return many;
}
export { plural };

export function fmtNumber(n) {
  n = Number(n) || 0;
  if (n >= 1e6) return `${(n / 1e6).toFixed(n >= 1e7 ? 0 : 1).replace('.0', '')} млн`;
  if (n >= 1e3) return `${(n / 1e3).toFixed(n >= 1e4 ? 0 : 1).replace('.0', '')} тыс.`;
  return String(n);
}

export function fmtViews(n) {
  n = Number(n) || 0;
  return `${fmtNumber(n)} ${plural(n, 'просмотр', 'просмотра', 'просмотров')}`;
}

export function fmtSubs(n) {
  n = Number(n) || 0;
  return `${fmtNumber(n)} ${plural(n, 'подписчик', 'подписчика', 'подписчиков')}`;
}

export function fmtBytes(n) {
  n = Number(n) || 0;
  const u = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
  let i = 0;
  while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
  return `${n.toFixed(i < 2 ? 0 : 1)} ${u[i]}`;
}

export function timeAgo(date) {
  if (!date) return '';
  const d = new Date(date);
  const diff = (Date.now() - d.getTime()) / 1000;
  if (diff < 45) return 'только что';
  const min = Math.floor(diff / 60);
  if (min < 60) return `${min} ${plural(min, 'минуту', 'минуты', 'минут')} назад`;
  const h = Math.floor(min / 60);
  if (h < 24) return `${h} ${plural(h, 'час', 'часа', 'часов')} назад`;
  const days = Math.floor(h / 24);
  if (days < 7) return `${days} ${plural(days, 'день', 'дня', 'дней')} назад`;
  const w = Math.floor(days / 7);
  if (days < 30) return `${w} ${plural(w, 'неделю', 'недели', 'недель')} назад`;
  const mo = Math.floor(days / 30);
  if (days < 365) return `${mo} ${plural(mo, 'месяц', 'месяца', 'месяцев')} назад`;
  const y = Math.floor(days / 365);
  return `${y} ${plural(y, 'год', 'года', 'лет')} назад`;
}

export function fmtDate(date, opts = {}) {
  if (!date) return '';
  return new Date(date).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric', ...opts });
}
export function fmtDateTime(date) {
  if (!date) return '';
  return new Date(date).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
export function fmtDateShort(date) {
  if (!date) return '';
  return new Date(date).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' });
}

export function toLocalInput(date) {
  if (!date) return '';
  const d = new Date(date);
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export const VISIBILITY = {
  public: { label: 'Публичное', hint: 'Доступно всем, в том числе без входа', icon: 'earth' },
  internal: { label: 'Для сотрудников', hint: 'Только зарегистрированным и одобренным пользователям', icon: 'accountGroup' },
  unlisted: { label: 'По ссылке', hint: 'Не отображается в списках и поиске, доступно по прямой ссылке', icon: 'link' },
  private: { label: 'Приватное', hint: 'Только вы, администраторы и выбранные пользователи', icon: 'lock' },
};

export const STATUS = {
  uploading: 'Загружается', queued: 'В очереди', processing: 'Обработка', ready: 'Готово', failed: 'Ошибка',
};

export function parseTime(str) {
  // "1:23:45" | "12:34" | "45" → секунды
  const parts = String(str || '').trim().split(':').map(Number);
  if (parts.some((p) => Number.isNaN(p))) return null;
  let s = 0;
  for (const p of parts) s = s * 60 + p;
  return s;
}

export function initials(name) {
  return String(name || '?').split(/\s+/).slice(0, 2).map((w) => w[0]?.toUpperCase() || '').join('');
}

export function avatarColor(seed) {
  const colors = ['#0F4382', '#1E6FA8', '#2A8C6E', '#8A5CB8', '#C2703A', '#B0353A', '#3A7CA5', '#5C6BC0'];
  let h = 0;
  for (const ch of String(seed || '')) h = (h * 31 + ch.charCodeAt(0)) >>> 0;
  return colors[h % colors.length];
}

/** Отдать файл пользователю из памяти; ссылку на объект освобождаем, иначе он живёт до закрытия вкладки. */
export function downloadBlob(blob, filename) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = filename;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1500);
}
