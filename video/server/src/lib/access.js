// Правила доступа: кто что может видеть и делать.
import { one } from '../db.js';

export const isStaff = (user) => !!user && (user.role === 'admin' || user.role === 'moderator');
export const isAdmin = (user) => !!user && user.role === 'admin';
export const isActive = (user) => !!user && user.status === 'active';

/**
 * Может ли пользователь (или гость) смотреть видео.
 * Учитывает видимость, статус модерации, блокировку, удаление и персональный доступ.
 */
export async function canViewVideo(video, user, shareTokens = null) {
  if (!video || video.deleted_at) return false;
  const owner = user && user.id === video.owner_id;
  if (owner || isStaff(user)) return true;
  if (video.archived_at) return false;   // архив (правила хранения 1.9): видно только владельцу и администраторам
  if (video.is_blocked) return false;
  if (video.moderation_status !== 'approved') return false;
  // Премьера — анонс: страница видна заранее (обратный отсчёт и чат), само видео открывается в назначенный час
  if (video.scheduled_at && new Date(video.scheduled_at) > new Date() && !video.premiere) return false;
  switch (video.visibility) {
    case 'public':
    case 'unlisted':
      return true;
    case 'internal':
      if (isActive(user)) return true;
      return shareLinkAllows(video.id, shareTokens);
    case 'private': {
      if (!isActive(user)) return shareLinkAllows(video.id, shareTokens);
      if (await hasPrivateAccess(video.id, user.id)) return true;
      return shareLinkAllows(video.id, shareTokens);
    }
    default:
      return false;
  }
}

/** Не начавшаяся премьера: страницу показываем, воспроизведение — нет. */
export function premierePending(video) {
  return !!(video && video.premiere && video.scheduled_at && new Date(video.scheduled_at) > new Date());
}

/** Состояние премьеры: scheduled — ждём, live — идёт показ, done — уже обычное видео. */
export function premiereState(video) {
  if (!video || !video.premiere || !video.scheduled_at) return null;
  const at = new Date(video.scheduled_at).getTime();
  const now = Date.now();
  if (now < at) return 'scheduled';
  if (now < at + (Number(video.duration) || 0) * 1000 + 15000) return 'live';
  return 'done';
}

/** Можно ли именно смотреть (а не только видеть карточку): премьера до начала не проигрывается. */
export async function canPlayVideo(video, user, shareTokens = null) {
  if (!(await canViewVideo(video, user, shareTokens))) return false;
  if (premierePending(video) && !(user && (user.id === video.owner_id || isStaff(user)))) return false;
  return true;
}

/** Персональный доступ, доступ через группу или через назначение «к просмотру». */
export async function hasPrivateAccess(videoId, userId) {
  const row = await one(
    `SELECT 1 WHERE EXISTS (SELECT 1 FROM video_access WHERE video_id = $1 AND user_id = $2)
        OR EXISTS (SELECT 1 FROM video_group_access ga JOIN group_members gm ON gm.group_id = ga.group_id WHERE ga.video_id = $1 AND gm.user_id = $2)
        OR EXISTS (
          SELECT 1 FROM assignments a JOIN assignment_targets t ON t.assignment_id = a.id
          WHERE a.status = 'active' AND (a.video_id = $1 OR a.playlist_id IN (SELECT playlist_id FROM playlist_items WHERE video_id = $1)
                 OR a.course_id IN (SELECT course_id FROM course_items WHERE video_id = $1 AND kind = 'video'))
            AND (t.target_type = 'all' OR t.user_id = $2 OR (t.target_type = 'group' AND t.group_id IN (SELECT group_id FROM group_members WHERE user_id = $2))))
        OR EXISTS (
          -- запись на опубликованный курс, в состав которого входит это видео
          SELECT 1 FROM course_enrollments e JOIN course_items ci ON ci.course_id = e.course_id
          JOIN courses c ON c.id = e.course_id
          WHERE e.user_id = $2 AND ci.video_id = $1 AND ci.kind = 'video' AND c.status = 'published')`,
    [videoId, userId],
  );
  return !!row;
}

/** Действующая защищённая ссылка (токен из cookie cv_share) на это видео: строка share_links или null. */
export async function shareLinkFor(videoId, tokens) {
  if (!tokens || !tokens.length) return null;
  // view_count учитывается при открытии ссылки (unlock); уже открывший её зритель продолжает смотреть
  return one(
    `SELECT * FROM share_links WHERE video_id = $1 AND token = ANY($2::text[]) AND revoked_at IS NULL
       AND (expires_at IS NULL OR expires_at > now()) AND (max_views IS NULL OR view_count < max_views) ORDER BY allow_download DESC LIMIT 1`,
    [videoId, tokens],
  );
}
export async function shareLinkAllows(videoId, tokens) {
  return !!(await shareLinkFor(videoId, tokens));
}

export function canAssign(user, settings) {
  if (!isActive(user)) return false;
  if (isStaff(user)) return true;
  return settings?.['assignments.who_can_assign'] === 'all';
}

export function canEditVideo(video, user) {
  if (!user || !video) return false;
  return user.id === video.owner_id || isStaff(user);
}

export function canViewPlaylist(pl, user) {
  if (!pl) return false;
  if (user && (user.id === pl.owner_id || isStaff(user))) return true;
  if (pl.visibility === 'public' || pl.visibility === 'unlisted') return true;
  if (pl.visibility === 'internal') return isActive(user);
  return false;
}

/** Действующая регистрация внешнего участника вебинара по токену (cookie cv_webinar или ?t=). */
export async function webinarGuest(streamId, tokens) {
  if (!tokens || !tokens.length) return null;
  return one(`SELECT * FROM live_registrations WHERE stream_id = $1 AND token = ANY($2::text[]) AND status = 'approved'`, [streamId, tokens]);
}

/** Доступ к эфиру с учётом внешних участников вебинара по персональной ссылке. */
export async function canViewLiveOrGuest(stream, user, tokens) {
  if (canViewLive(stream, user)) return true;
  if (!stream?.webinar || !stream.reg_external) return false;
  return !!(await webinarGuest(stream.id, tokens));
}

export function canViewLive(stream, user) {
  if (!stream) return false;
  if (user && (user.id === stream.owner_id || isStaff(user))) return true;
  if (stream.visibility === 'public' || stream.visibility === 'unlisted') return true;
  if (stream.visibility === 'internal') return isActive(user);
  return false;
}

/** SQL-условие «видео видимо этому пользователю в списках» (без unlisted/private). */
export function listVisibilitySql(user, alias = 'v') {
  // ВАЖНО: результат оборачивается в скобки — иначе OR в соседнем условии (например `hd`) снимает фильтр видимости
  const base = `${alias}.deleted_at IS NULL AND ${alias}.archived_at IS NULL AND ${alias}.status = 'ready' AND ${alias}.is_blocked = false AND ${alias}.moderation_status = 'approved' AND (${alias}.scheduled_at IS NULL OR ${alias}.scheduled_at <= now() OR ${alias}.premiere)`;
  const vis = isActive(user) || isStaff(user) ? `${alias}.visibility IN ('public','internal')` : `${alias}.visibility = 'public'`;
  return `(${base} AND ${vis})`;
}

export function canUpload(user, settings) {
  if (!isActive(user)) return false;
  const who = settings['upload.who_can_upload'];
  if (isAdmin(user)) return true;
  if (who === 'admins') return false;
  if (who === 'allowed') return !!user.can_upload;
  return user.can_upload !== false;
}

export function canStream(user, settings) {
  if (!isActive(user) || !settings['live.enabled']) return false;
  const who = settings['live.who_can_stream'];
  if (isAdmin(user)) return true;
  if (who === 'admins') return false;
  if (who === 'allowed') return !!user.can_stream;
  return user.can_stream !== false;
}

// --- Вебинары и витрины (1.12: общие правила для страниц и для раздачи файлов /media/) ---------------
export function canManageWebinar(s, user) {
  if (!s || !user) return false;
  if (s.owner_id === user.id || isStaff(user)) return true;
  return Array.isArray(s.cohosts) && s.cohosts.includes(user.id);
}

/** Кто видит страницу вебинара (и его обложку). */
export function canSeeWebinar(s, user) {
  if (canManageWebinar(s, user)) return true;
  // Вебинар с регистрацией внешних участников: страница открывается по прямой ссылке всем,
  // иначе приглашённый со стороны не смог бы записаться. Сама комната закрыта до подтверждения.
  if (s.webinar && s.registration && s.reg_external && s.visibility !== 'private') return true;
  if (s.visibility === 'public' || s.visibility === 'unlisted') return true;
  if (s.visibility === 'internal') return isActive(user);
  return false;
}

/** Кто видит витрину (и её обложку). */
export function canSeeShowcase(sc, user) {
  if (!sc || !sc.enabled) return isStaff(user);
  if (isStaff(user)) return true;
  if (sc.visibility === 'public' || sc.visibility === 'unlisted') return true;
  return isActive(user);
}
