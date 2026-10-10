// Карточки для корпоративных мессенджеров: один и тот же вебхук можно отправить
// обычным JSON, в Slack/Mattermost (совместимый формат) или в Microsoft Teams (Adaptive Card).
import { config } from '../config.js';

const TITLES = {
  'video.published': 'Опубликовано видео',
  'video.ready': 'Видео обработано',
  'video.failed': 'Не удалось обработать видео',
  'video.expired': 'Срок публикации видео истёк',
  'live.started': 'Начался эфир',
  'live.ended': 'Эфир завершён',
  'comment.created': 'Новый комментарий',
  'report.created': 'Жалоба на контент',
  'user.registered': 'Новая регистрация',
  'user.approved': 'Сотрудник одобрен',
  'assignment.created': 'Назначен обязательный просмотр',
  'assignment.completed': 'Обязательный просмотр выполнен',
  'course.completed': 'Курс пройден',
  'review.requested': 'Видео отправлено на согласование',
  'review.approved': 'Видео согласовано',
  'review.changes_requested': 'Видео вернули на доработку',
};

const abs = (url) => (url && url.startsWith('/') ? `${config.baseUrl}${url}` : url || config.baseUrl);
const clip = (s, n = 300) => String(s ?? '').replace(/\s+/g, ' ').trim().slice(0, n);

/** Человеческое описание события: заголовок, строки-подробности и ссылка. */
export function describeEvent(event, payload = {}) {
  const d = payload.data || payload;
  const title = TITLES[event] || event;
  const facts = [];
  let link = null;
  let text = '';
  const v = d.video || d.recording;
  if (v) {
    text = clip(v.title, 200);
    link = abs(v.url || (v.shortId ? `/watch/${v.shortId}` : null));
    if (v.owner) facts.push(['Автор', clip(v.owner, 80)]);
    if (v.visibility) facts.push(['Доступ', clip(v.visibility, 40)]);
  }
  if (d.stream) {
    text = clip(d.stream.title, 200);
    link = abs(d.stream.url || (d.stream.shortId ? `/live/${d.stream.shortId}` : null));
  }
  if (d.comment) { facts.push(['Комментарий', clip(d.comment.body || d.comment.text, 200)]); link = link || abs(d.comment.url); }
  if (d.user) { facts.push(['Сотрудник', clip(d.user.displayName || d.user.email, 120)]); link = link || abs(d.user.url); }
  if (d.assignment) { text = text || clip(d.assignment.title, 200); link = link || abs(d.assignment.url); }
  if (d.course) { text = text || clip(d.course.title, 200); link = link || abs(d.course.url); }
  if (d.by) facts.push(['Кто', clip(d.by, 80)]);
  if (d.comment && typeof d.comment === 'string') facts.push(['Замечание', clip(d.comment, 200)]);
  if (d.reviewers) facts.push(['Рецензентов', String(d.reviewers)]);
  if (d.reason) facts.push(['Причина', clip(d.reason, 160)]);
  return { title, text: text || clip(d.title, 200), facts, link: link || config.baseUrl };
}

const COLOR = (event) => (
  /failed|report|changes_requested|expired/.test(event) ? '#C0392B'
    : /approved|completed|published|ready/.test(event) ? '#1E8E4E'
      : '#0F4382');

/** Slack и Mattermost понимают один и тот же формат вложений. */
export function toSlack(event, payload, siteName = 'CorpVideo') {
  const e = describeEvent(event, payload);
  return {
    username: siteName,
    text: `*${e.title}*`,
    attachments: [{
      color: COLOR(event),
      title: e.text || e.title,
      title_link: e.link,
      fields: e.facts.map(([t, v]) => ({ title: t, value: v, short: v.length < 40 })),
      footer: siteName,
      ts: Math.floor(Date.now() / 1000),
    }],
  };
}

/** Microsoft Teams: Adaptive Card во вложении сообщения. */
export function toTeams(event, payload, siteName = 'CorpVideo') {
  const e = describeEvent(event, payload);
  const body = [
    { type: 'TextBlock', text: e.title, weight: 'bolder', size: 'large', wrap: true },
  ];
  if (e.text) body.push({ type: 'TextBlock', text: e.text, wrap: true });
  if (e.facts.length) body.push({ type: 'FactSet', facts: e.facts.map(([t, v]) => ({ title: t, value: v })) });
  body.push({ type: 'TextBlock', text: siteName, isSubtle: true, size: 'small', wrap: true });
  return {
    type: 'message',
    attachments: [{
      contentType: 'application/vnd.microsoft.card.adaptive',
      content: {
        type: 'AdaptiveCard', $schema: 'http://adaptivecards.io/schemas/adaptive-card.json', version: '1.4',
        body,
        actions: e.link ? [{ type: 'Action.OpenUrl', title: 'Открыть', url: e.link }] : [],
      },
    }],
  };
}

/** Тело запроса для выбранного формата. */
export function formatPayload(format, event, payload, siteName) {
  if (format === 'slack' || format === 'mattermost') return toSlack(event, payload, siteName);
  if (format === 'teams') return toTeams(event, payload, siteName);
  return payload;
}
