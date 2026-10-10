// Отправка электронной почты: транспорт по настройкам SMTP, шаблоны писем, постановка в очередь заданий.
import nodemailer from 'nodemailer';
import { getSettings } from './settings.js';
import { config } from '../config.js';
import { query } from '../db.js';
import { escapeHtml } from './util.js';

let transport = null;
let transportKey = '';

async function getTransport() {
  const s = await getSettings('smtp.');
  if (!s['smtp.enabled'] || !s['smtp.host']) return null;
  const key = JSON.stringify(s);
  if (transport && transportKey === key) return transport;
  transport = nodemailer.createTransport({
    host: s['smtp.host'],
    port: Number(s['smtp.port']) || 587,
    secure: !!s['smtp.secure'],
    auth: s['smtp.user'] ? { user: s['smtp.user'], pass: s['smtp.password'] } : undefined,
    tls: { rejectUnauthorized: s['smtp.reject_unauthorized'] !== false },
    connectionTimeout: 15000,
  });
  transportKey = key;
  return transport;
}

/** Проверочное письмо по переданным (возможно, ещё не сохранённым) настройкам SMTP. */
export async function sendTestMail(s, to) {
  if (!s['smtp.host']) throw new Error('Не указан SMTP-сервер');
  const t = nodemailer.createTransport({
    host: s['smtp.host'],
    port: Number(s['smtp.port']) || 587,
    secure: !!s['smtp.secure'],
    auth: s['smtp.user'] ? { user: s['smtp.user'], pass: s['smtp.password'] } : undefined,
    tls: { rejectUnauthorized: s['smtp.reject_unauthorized'] !== false },
    connectionTimeout: 15000,
  });
  try {
    const si = await siteInfo();
    const from = s['smtp.from'] || s['smtp.user'];
    return await t.sendMail({
      from: `"${s['site.name'] || si.siteName}" <${from}>`, to, replyTo: s['smtp.reply_to'] || undefined,
      subject: `${si.siteName}: проверка SMTP`,
      html: layout({ ...si, title: 'Проверка настроек почты', body: '<p>Если вы читаете это письмо — SMTP настроен верно.</p>' }),
    });
  } finally { try { t.close(); } catch { /* ignore */ } }
}

export async function emailEnabled() {
  const s = await getSettings('smtp.');
  return !!(s['smtp.enabled'] && s['smtp.host']);
}

/** Немедленная отправка (используется воркером и кнопкой «проверить»). */
export async function sendMailNow({ to, subject, html, text }) {
  const t = await getTransport();
  if (!t) throw new Error('SMTP не настроен');
  const s = await getSettings('smtp.');
  const site = await getSettings('site.');
  const from = s['smtp.from'] || s['smtp.user'];
  return t.sendMail({
    from: `"${site['site.name']}" <${from}>`,
    to, subject, html, text: text || html.replace(/<[^>]+>/g, ''),
    replyTo: s['smtp.reply_to'] || undefined,
  });
}

/** Поставить письмо в очередь (отправит воркер, с повторами). */
export async function queueMail({ to, subject, html, text }) {
  if (!(await emailEnabled())) return false;
  // Сотрудники портала без почты получают адрес-заглушку в домене .invalid — писем на него не шлём.
  if (!to || /\.invalid>?\s*$/i.test(String(to).trim())) return false;
  await query(`INSERT INTO jobs(type, payload, priority, max_attempts) VALUES ('email', $1::jsonb, 5, 5)`, [JSON.stringify({ to, subject, html, text })]);
  return true;
}

/** Обёртка письма в фирменном стиле. */
export function layout({ title, body, siteName, baseUrl, footer }) {
  return `<!doctype html><html lang="ru"><body style="margin:0;padding:0;background:#f3f6fa;font-family:Roboto,Arial,sans-serif;color:#1d1e1e">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f6fa;padding:32px 12px">
    <tr><td align="center">
      <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-radius:24px;overflow:hidden">
        <tr><td style="background:#0f4382;padding:24px 32px;color:#fff;font-size:22px;font-weight:700;letter-spacing:.5px;text-transform:uppercase">${escapeHtml(siteName)}</td></tr>
        <tr><td style="padding:32px">
          <h1 style="margin:0 0 16px;font-size:22px;color:#0f4382">${escapeHtml(title)}</h1>
          <div style="font-size:16px;line-height:1.5">${body}</div>
        </td></tr>
        <tr><td style="padding:16px 32px 28px;font-size:13px;color:#9b9b9b">${escapeHtml(footer || '')}<br><a href="${baseUrl}" style="color:#0f4382">${baseUrl}</a></td></tr>
      </table>
    </td></tr>
  </table></body></html>`;
}

export function button(url, label) {
  return `<p style="margin:24px 0"><a href="${url}" style="display:inline-block;padding:12px 28px;border-radius:999px;background:#0f4382;color:#fff;text-decoration:none;font-weight:500;text-transform:uppercase;letter-spacing:.5px;font-size:14px">${escapeHtml(label)}</a></p>
  <p style="font-size:13px;color:#9b9b9b">Если кнопка не работает, скопируйте ссылку в браузер:<br><a href="${url}" style="color:#0f4382;word-break:break-all">${url}</a></p>`;
}

export async function siteInfo() {
  const s = await getSettings('site.');
  return { siteName: s['site.name'], baseUrl: config.baseUrl, footer: s['site.footer_text'] };
}

export const templates = {
  async verifyEmail({ to, name, url }) {
    const si = await siteInfo();
    return queueMail({
      to,
      subject: `${si.siteName}: подтвердите адрес электронной почты`,
      html: layout({ ...si, title: 'Подтверждение адреса', body: `<p>Здравствуйте, ${escapeHtml(name)}!</p><p>Вы зарегистрировались на портале «${escapeHtml(si.siteName)}». Подтвердите адрес электронной почты, чтобы заявка ушла администратору.</p>${button(url, 'Подтвердить адрес')}<p>Ссылка действует 48 часов.</p>` }),
    });
  },
  async approved({ to, name, url }) {
    const si = await siteInfo();
    return queueMail({
      to,
      subject: `${si.siteName}: ваша учётная запись одобрена`,
      html: layout({ ...si, title: 'Доступ открыт', body: `<p>Здравствуйте, ${escapeHtml(name)}!</p><p>Администратор одобрил вашу заявку. Теперь вы можете входить на портал, смотреть внутренние видео, загружать свои и подписываться на каналы коллег.</p>${button(url, 'Перейти на портал')}` }),
    });
  },
  async rejected({ to, name, note }) {
    const si = await siteInfo();
    return queueMail({
      to,
      subject: `${si.siteName}: заявка на регистрацию отклонена`,
      html: layout({ ...si, title: 'Заявка отклонена', body: `<p>Здравствуйте, ${escapeHtml(name)}.</p><p>К сожалению, администратор отклонил вашу заявку на доступ к порталу.${note ? `</p><p><b>Причина:</b> ${escapeHtml(note)}` : ''}</p>` }),
    });
  },
  /** Повторная регистрация на уже занятый адрес: сообщаем владельцу, а не тому, кто регистрируется. */
  async accountExists({ to, name, loginUrl, resetUrl }) {
    const si = await siteInfo();
    return queueMail({
      to,
      subject: `${si.siteName}: попытка регистрации с вашим адресом`,
      html: layout({ ...si, title: 'У вас уже есть учётная запись', body: `<p>Здравствуйте, ${escapeHtml(name || 'коллега')}!</p><p>Кто-то (возможно, вы) пытался зарегистрироваться на портале «${escapeHtml(si.siteName)}» с этим адресом. Учётная запись с ним уже есть — новая не создана.</p>${button(loginUrl, 'Войти')}<p>Не помните пароль? <a href="${resetUrl}" style="color:#0f4382">Восстановите его</a>. Если это были не вы — просто проигнорируйте письмо.</p>` }),
    });
  },
  async resetPassword({ to, name, url }) {
    const si = await siteInfo();
    return queueMail({
      to,
      subject: `${si.siteName}: восстановление пароля`,
      html: layout({ ...si, title: 'Восстановление пароля', body: `<p>Здравствуйте, ${escapeHtml(name)}!</p><p>Вы (или кто-то другой) запросили сброс пароля. Если это были не вы — просто проигнорируйте письмо.</p>${button(url, 'Задать новый пароль')}<p>Ссылка действует 2 часа.</p>` }),
    });
  },
  async invite({ to, url, inviterName }) {
    const si = await siteInfo();
    return queueMail({
      to,
      subject: `${si.siteName}: приглашение на корпоративный видеопортал`,
      html: layout({ ...si, title: 'Вас пригласили', body: `<p>${escapeHtml(inviterName)} приглашает вас на корпоративный видеопортал «${escapeHtml(si.siteName)}».</p>${button(url, 'Принять приглашение')}<p>Ссылка действует 7 дней.</p>` }),
    });
  },
  async adminNewRegistration({ to, userName, userEmail, url }) {
    const si = await siteInfo();
    return queueMail({
      to,
      subject: `${si.siteName}: новая заявка на регистрацию — ${userName}`,
      html: layout({ ...si, title: 'Новая заявка на регистрацию', body: `<p><b>${escapeHtml(userName)}</b> (${escapeHtml(userEmail)}) подтвердил(а) адрес и ждёт одобрения.</p>${button(url, 'Открыть заявки')}` }),
    });
  },
  async newVideo({ to, channelName, videoTitle, url, thumbUrl }) {
    const si = await siteInfo();
    return queueMail({
      to,
      subject: `${channelName}: новое видео «${videoTitle}»`,
      html: layout({ ...si, title: 'Новое видео на канале', body: `<p><b>${escapeHtml(channelName)}</b> опубликовал(а) видео <b>${escapeHtml(videoTitle)}</b>.</p>${thumbUrl ? `<p><a href="${url}"><img src="${thumbUrl}" alt="" style="max-width:100%;border-radius:12px"></a></p>` : ''}${button(url, 'Смотреть')}` }),
    });
  },
  async commentReply({ to, actorName, videoTitle, text, url }) {
    const si = await siteInfo();
    return queueMail({
      to,
      subject: `${actorName} ответил(а) на ваш комментарий`,
      html: layout({ ...si, title: 'Новый ответ', body: `<p><b>${escapeHtml(actorName)}</b> ответил(а) на ваш комментарий к видео «${escapeHtml(videoTitle)}»:</p><blockquote style="border-left:3px solid #79a7c6;margin:0;padding:8px 16px;color:#444">${escapeHtml(text)}</blockquote>${button(url, 'Открыть')}` }),
    });
  },
  async videoModerated({ to, videoTitle, approved, note, url }) {
    const si = await siteInfo();
    return queueMail({
      to,
      subject: `${si.siteName}: видео «${videoTitle}» ${approved ? 'опубликовано' : 'отклонено'}`,
      html: layout({ ...si, title: approved ? 'Видео одобрено' : 'Видео отклонено', body: `<p>Модератор ${approved ? 'одобрил' : 'отклонил'} ваше видео <b>${escapeHtml(videoTitle)}</b>.${note ? `</p><p><b>Комментарий:</b> ${escapeHtml(note)}` : ''}</p>${button(url, 'Открыть')}` }),
    });
  },
  async videoReady({ to, videoTitle, url }) {
    const si = await siteInfo();
    return queueMail({
      to,
      subject: `${si.siteName}: видео «${videoTitle}» обработано`,
      html: layout({ ...si, title: 'Обработка завершена', body: `<p>Видео <b>${escapeHtml(videoTitle)}</b> обработано и доступно для просмотра.</p>${button(url, 'Открыть')}` }),
    });
  },
  async assignment({ to, name, title, due, note, url }) {
    const si = await siteInfo();
    const dueTxt = due ? `<p>Срок: <b>${new Date(due).toLocaleDateString('ru-RU')}</b>.</p>` : '';
    return queueMail({
      to,
      subject: `${si.siteName}: вам назначено к просмотру «${title}»`,
      html: layout({ ...si, title: 'Обязательный просмотр', body: `<p>Здравствуйте, ${escapeHtml(name)}!</p><p>Вам назначено к просмотру: <b>${escapeHtml(title)}</b>.</p>${dueTxt}${note ? `<p>${escapeHtml(note)}</p>` : ''}${button(url, 'Смотреть')}` }),
    });
  },
  async assignmentReminder({ to, name, title, due, percent, url }) {
    const si = await siteInfo();
    return queueMail({
      to,
      subject: `${si.siteName}: напоминание — «${title}» до ${new Date(due).toLocaleDateString('ru-RU')}`,
      html: layout({ ...si, title: 'Напоминание о просмотре', body: `<p>Здравствуйте, ${escapeHtml(name)}!</p><p>Срок просмотра <b>${escapeHtml(title)}</b> — ${new Date(due).toLocaleDateString('ru-RU')}. Просмотрено: ${percent}%.</p>${button(url, 'Досмотреть')}` }),
    });
  },
  // --- Вебинары (1.10) -------------------------------------------------------------------
  async webinarConfirm({ to, name, title, when, url, note, materials = [], pending = false, waitlist = false }) {
    const si = await siteInfo();
    const head = pending ? 'Заявка принята' : waitlist ? 'Вы в листе ожидания' : 'Вы записаны на вебинар';
    const lead = pending
      ? 'Организатор проверит заявку и пришлёт подтверждение со ссылкой для входа.'
      : waitlist
        ? 'Мест пока нет — как только место освободится, мы пришлём ссылку для входа.'
        : 'Ссылка ниже — персональная, по ней вы попадёте в комнату вебинара.';
    const mat = materials.length ? `<p><b>Материалы:</b> ${materials.map((m) => `<a href="${m.url}">${escapeHtml(m.name)}</a>`).join(', ')}</p>` : '';
    return queueMail({
      to,
      subject: `${si.siteName}: ${pending ? 'заявка на вебинар' : 'вы записаны на вебинар'} «${title}»`,
      html: layout({ ...si, title: head, body: `<p>Здравствуйте, ${escapeHtml(name)}!</p><p><b>${escapeHtml(title)}</b>${when ? `<br>Начало: ${escapeHtml(when)}` : ''}</p><p>${lead}</p>${note ? `<p>${escapeHtml(note)}</p>` : ''}${pending || waitlist ? '' : button(url, 'Перейти к вебинару')}${mat}` }),
    });
  },
  async webinarReminder({ to, name, title, when, url, leadLabel }) {
    const si = await siteInfo();
    return queueMail({
      to,
      subject: `${si.siteName}: вебинар «${title}» ${leadLabel}`,
      html: layout({ ...si, title: 'Напоминание о вебинаре', body: `<p>Здравствуйте, ${escapeHtml(name)}!</p><p>Вебинар <b>${escapeHtml(title)}</b> начнётся ${escapeHtml(leadLabel)}${when ? ` (${escapeHtml(when)})` : ''}.</p>${button(url, 'Войти в комнату вебинара')}` }),
    });
  },
  async webinarFollowup({ to, name, title, url, recordingUrl, materials = [], certUrl = null, attended = true }) {
    const si = await siteInfo();
    const mat = materials.length ? `<p><b>Материалы вебинара:</b> ${materials.map((m) => `<a href="${m.url}">${escapeHtml(m.name)}</a>`).join(', ')}</p>` : '';
    return queueMail({
      to,
      subject: `${si.siteName}: запись вебинара «${title}»`,
      html: layout({ ...si, title: attended ? 'Спасибо за участие' : 'Запись вебинара', body: `<p>Здравствуйте, ${escapeHtml(name)}!</p><p>${attended ? 'Спасибо, что были с нами на вебинаре' : 'Вы записывались на вебинар'} <b>${escapeHtml(title)}</b>.</p>${button(recordingUrl || url, recordingUrl ? 'Смотреть запись' : 'Открыть страницу вебинара')}${mat}${certUrl ? `<p>Ваш сертификат участника: <a href="${certUrl}">открыть</a></p>` : ''}` }),
    });
  },
  async webinarInvite({ to, title, when, url, inviterName, note }) {
    const si = await siteInfo();
    return queueMail({
      to,
      subject: `${si.siteName}: приглашение на вебинар «${title}»`,
      html: layout({ ...si, title: 'Приглашение на вебинар', body: `<p>${escapeHtml(inviterName || si.siteName)} приглашает вас на вебинар <b>${escapeHtml(title)}</b>${when ? `<br>Начало: ${escapeHtml(when)}` : ''}.</p>${note ? `<p>${escapeHtml(note)}</p>` : ''}${button(url, 'Записаться')}` }),
    });
  },
  async test({ to }) {
    const si = await siteInfo();
    return sendMailNow({ to, subject: `${si.siteName}: проверка SMTP`, html: layout({ ...si, title: 'Проверка настроек почты', body: '<p>Если вы читаете это письмо — SMTP настроен верно.</p>' }) });
  },
  /** Еженедельный дайджест: новые видео по подпискам и на портале, назначения со сроком. */
  async digest({ to, name, videos = [], assignments = [], live = [], periodLabel = 'за неделю' }) {
    const si = await siteInfo();
    const item = (v) => `<tr><td style="padding:6px 0;vertical-align:top;width:120px">${v.thumbnailUrl ? `<a href="${si.baseUrl}${v.url}"><img src="${si.baseUrl}${v.thumbnailUrl}" width="112" height="63" style="border-radius:6px;object-fit:cover;display:block" alt=""></a>` : ''}</td><td style="padding:6px 8px;vertical-align:top"><a href="${si.baseUrl}${v.url}" style="font-weight:600;color:#0F4382;text-decoration:none">${escapeHtml(v.title)}</a><div style="color:#5A6B7F;font-size:13px">${escapeHtml(v.ownerName || '')}${v.duration ? ` · ${v.duration}` : ''}</div></td></tr>`;
    let body = `<p>Здравствуйте, ${escapeHtml(name)}! Что нового на портале ${escapeHtml(periodLabel)}:</p>`;
    if (assignments.length) body += `<h3 style="margin:16px 0 6px;font-size:16px">Назначено к просмотру</h3><ul>${assignments.map((a) => `<li><a href="${si.baseUrl}${a.url}" style="color:#0F4382">${escapeHtml(a.title)}</a>${a.due ? ` — до ${escapeHtml(a.due)}` : ''}${a.percent != null ? ` (просмотрено ${a.percent}%)` : ''}</li>`).join('')}</ul>`;
    if (live.length) body += `<h3 style="margin:16px 0 6px;font-size:16px">Ближайшие эфиры</h3><ul>${live.map((l) => `<li><a href="${si.baseUrl}${l.url}" style="color:#0F4382">${escapeHtml(l.title)}</a> — ${escapeHtml(l.when)}</li>`).join('')}</ul>`;
    if (videos.length) body += `<h3 style="margin:16px 0 6px;font-size:16px">Новые видео</h3><table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%">${videos.map(item).join('')}</table>`;
    body += `${button(si.baseUrl + '/', 'Открыть портал')}<p style="color:#5A6B7F;font-size:12px">Отключить дайджест можно в настройках уведомлений на портале.</p>`;
    return queueMail({ to, subject: `${si.siteName}: дайджест ${periodLabel}`, html: layout({ ...si, title: 'Дайджест портала', body }) });
  },
};
