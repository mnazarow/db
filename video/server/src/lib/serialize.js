// Преобразование строк БД в объекты API (camelCase, только нужные поля).
import { storage } from './storage.js';
import { mediaUrl, storedMediaUrl } from './urls.js';

export function userPublic(u) {
  if (!u) return null;
  return {
    id: u.id,
    handle: u.handle,
    displayName: u.display_name,
    avatarUrl: mediaUrl(u.avatar_path),
    bannerUrl: mediaUrl(u.banner_path),
    bio: u.bio || '',
    links: u.links || [],
    role: u.role,
    subscriberCount: u.subscriber_count || 0,
    videoCount: u.video_count || 0,
    totalViews: u.total_views || 0,
    createdAt: u.created_at,
    isLdap: u.auth_provider === 'ldap',
  };
}

export function userSelf(u) {
  if (!u) return null;
  return {
    ...userPublic(u),
    email: u.email,
    status: u.status,
    canUpload: u.can_upload,
    canStream: u.can_stream,
    prefs: u.prefs || {},
    totpEnabled: !!u.totp_enabled,
    authProvider: u.auth_provider,
    emailVerified: !!u.email_verified_at,
    lastLoginAt: u.last_login_at,
    telegram: u.telegram_chat_id ? { linked: true, username: u.telegram_username, linkedAt: u.telegram_linked_at } : { linked: false },
  };
}

export function userAdmin(u) {
  return {
    ...userSelf(u),
    approvedAt: u.approved_at,
    approvedBy: u.approved_by,
    rejectionNote: u.rejection_note,
    lastSeenAt: u.last_seen_at,
    lockedUntil: u.locked_until,
    failedLogins: u.failed_logins,
    ldapDn: u.ldap_dn,
    deletedAt: u.deleted_at,
    updatedAt: u.updated_at,
  };
}

export function ownerFromRow(v) {
  return {
    id: v.owner_id,
    handle: v.owner_handle,
    displayName: v.owner_name,
    avatarUrl: mediaUrl(v.owner_avatar),
    subscriberCount: v.owner_subscribers ?? undefined,
  };
}

export function videoCard(v, extra = {}) {
  if (!v) return null;
  return {
    id: v.id,
    shortId: v.short_id,
    title: v.title,
    description: v.description ? String(v.description).slice(0, 300) : '',
    duration: Number(v.duration) || 0,
    thumbnailUrl: mediaUrl(v.thumbnail_path),
    viewCount: v.view_count || 0,
    likeCount: v.like_count || 0,
    commentCount: v.comment_count || 0,
    visibility: v.visibility,
    status: v.status,
    moderationStatus: v.moderation_status,
    isBlocked: !!v.is_blocked,
    isShort: !!v.is_short,
    isLiveRecording: !!v.is_live_recording,
    publishedAt: v.published_at,
    scheduledAt: v.scheduled_at,
    premiere: !!v.premiere,
    premiereState: v.premiere && v.scheduled_at
      ? (Date.now() < new Date(v.scheduled_at).getTime() ? 'scheduled'
        : (Date.now() < new Date(v.scheduled_at).getTime() + (Number(v.duration) || 0) * 1000 + 15000 ? 'live' : 'done'))
      : null,
    createdAt: v.created_at,
    categoryId: v.category_id,
    categoryName: v.category_name,
    categorySlug: v.category_slug,
    processingProgress: v.processing_progress,
    processingStage: v.processing_stage,
    owner: v.owner_name !== undefined ? ownerFromRow(v) : undefined,
    progressPosition: v.progress_position !== undefined ? Number(v.progress_position) : undefined,
    hasQuiz: !!v.has_quiz,
    archived: !!v.archived_at,
    legalHold: !!v.legal_hold,
    freshUntil: v.fresh_until || null,
    // раскадровка для анимированного превью карточки при наведении
    storyboardUrl: v.storyboard_path ? mediaUrl(v.storyboard_path) : null,
    storyboardMeta: v.storyboard_meta || null,
    ...extra,
  };
}

export function videoFull(v, { renditions = [], subtitles = [], viewer = null, userLike = 0, subscribed = false, inWatchLater = false, accessUsers = undefined, accessGroups = undefined } = {}) {
  const card = videoCard(v);
  const isOwner = viewer && viewer.id === v.owner_id;
  return {
    ...card,
    description: v.description || '',
    tags: v.tags || [],
    language: v.language,
    width: v.width, height: v.height, fps: v.fps ? Number(v.fps) : null,
    hlsUrl: v.hls_path ? mediaUrl(v.hls_path) : null,
    mp4Url: v.mp4_path ? mediaUrl(v.mp4_path) : null,
    originalAvailable: !!(v.original_path && v.original_kept),
    originalFilename: v.original_filename,
    originalSize: v.original_size,
    storageBytes: v.storage_bytes,
    storyboardUrl: v.storyboard_path ? mediaUrl(v.storyboard_path) : null,
    storyboardMeta: v.storyboard_meta || null,
    chapters: v.chapters || [],
    dislikeCount: v.dislike_count || 0,
    watchSeconds: v.watch_seconds || 0,
    commentsMode: v.comments_mode,
    premiereChat: !!v.premiere_chat,
    hasScenario: !!v.has_scenario,
    reviewStatus: v.review_status || null,
    hasNotes: !!v.has_notes,
    // 1.9 — жизненный цикл и размытие
    archivedAt: v.archived_at || null,
    archivedReason: v.archived_reason || null,
    legalHold: !!v.legal_hold,
    freshUntil: v.fresh_until || null,
    freshConfirmedAt: v.fresh_confirmed_at || null,
    blurRegions: v.blur_regions || [],
    allowDownload: !!v.allow_download,
    introEnd: v.intro_end != null ? Number(v.intro_end) : null,
    outroStart: v.outro_start != null ? Number(v.outro_start) : null,
    allowEmbed: !!v.allow_embed,
    allowRatings: !!v.allow_ratings,
    thumbnailCandidates: (v.thumbnail_candidates || []).map(mediaUrl),
    renditions: renditions.map((r) => ({ label: r.label, width: r.width, height: r.height, bandwidth: r.bandwidth, bytes: r.bytes })),
    subtitles: subtitles.map(subtitleOut),
    processingError: isOwner || (viewer && (viewer.role === 'admin' || viewer.role === 'moderator')) ? v.processing_error : undefined,
    moderationNote: isOwner || (viewer && (viewer.role === 'admin' || viewer.role === 'moderator')) ? v.moderation_note : undefined,
    blockReason: isOwner ? v.block_reason : undefined,
    originalKept: v.original_kept,
    liveStreamId: v.live_stream_id,
    updatedAt: v.updated_at,
    viewer: viewer ? { like: userLike, subscribed, inWatchLater, isOwner } : null,
    accessUsers,
    accessGroups: accessGroups ? accessGroups.map((g) => ({ id: g.id, name: g.name, memberCount: g.memberCount ?? g.member_count })) : undefined,
    hasTranscript: (isOwner || (viewer && (viewer.role === 'admin' || viewer.role === 'moderator'))) ? !!v.transcript : undefined,
    viewerWatermark: !!v.viewer_watermark,
    version: v.version || 1,
    replacedAt: v.replaced_at,
    hasQuiz: !!v.has_quiz,
    expiresAt: v.expires_at,
    expiredAt: v.expired_at,
    ragSyncedAt: (isOwner || (viewer && (viewer.role === 'admin' || viewer.role === 'moderator'))) ? v.rag_synced_at : undefined,
    sourceUrl: isOwner ? v.source_url : undefined,
    aiSuggestions: (isOwner || (viewer && (viewer.role === 'admin' || viewer.role === 'moderator'))) ? v.ai_suggestions || null : undefined,
    // 1.3
    cards: v.cards || [],
    endScreen: v.end_screen || null,
    clipOf: v.clip_of || null,
    clipRange: v.clip_range || null,
    audioUrl: v.audio_path ? mediaUrl(v.audio_path) : null,
    ocrStatus: v.ocr_status || null,
    hasScreenText: v.ocr_status === 'done' && !!v.screen_text,
    hasChatReplay: !!(v.is_live_recording && v.live_stream_id && (v.recording_started_at || v.live_stream_id)),
    editHistory: isOwner || (viewer && (viewer.role === 'admin' || viewer.role === 'moderator')) ? v.edit_history || [] : undefined,
  };
}

export function subtitleOut(s) {
  return {
    id: s.id, language: s.language, label: s.label, kind: s.kind, status: s.status,
    url: s.path ? mediaUrl(s.path) : null, isDefault: !!s.is_default, error: s.error, createdAt: s.created_at, translatedFrom: s.translated_from || null,
  };
}

export function commentOut(c, viewer) {
  return {
    id: c.id,
    videoId: c.video_id,
    parentId: c.parent_id,
    rootId: c.root_id,
    body: c.status === 'deleted' ? '' : c.body,
    status: c.status,
    isPinned: !!c.is_pinned,
    isHearted: !!c.is_hearted,
    likeCount: c.like_count || 0,
    dislikeCount: c.dislike_count || 0,
    replyCount: c.reply_count || 0,
    editedAt: c.edited_at,
    createdAt: c.created_at,
    author: c.author_name !== undefined ? {
      id: c.user_id, handle: c.author_handle, displayName: c.author_name, avatarUrl: mediaUrl(c.author_avatar), role: c.author_role,
    } : { id: c.user_id },
    isVideoOwner: c.user_id === c.video_owner_id,
    viewerLike: c.viewer_like || 0,
    canEdit: !!viewer && (viewer.id === c.user_id || viewer.role === 'admin' || viewer.role === 'moderator'),
  };
}

export function playlistOut(p) {
  return {
    id: p.id,
    title: p.title,
    description: p.description || '',
    visibility: p.visibility,
    kind: p.kind,
    itemCount: p.item_count || 0,
    thumbnailUrl: mediaUrl(p.first_thumbnail),
    owner: p.owner_name !== undefined ? ownerFromRow(p) : { id: p.owner_id },
    createdAt: p.created_at,
    updatedAt: p.updated_at,
    containsVideo: p.contains_video === undefined ? undefined : !!p.contains_video,
  };
}

export function liveOut(s, { viewer = null, withKey = false } = {}) {
  const isOwner = viewer && viewer.id === s.owner_id;
  return {
    id: s.id,
    shortId: s.short_id,
    title: s.title,
    description: s.description || '',
    visibility: s.visibility,
    status: s.status,
    chatEnabled: !!s.chat_enabled,
    record: !!s.record,
    registration: !!s.registration,
    captions: !!s.captions,
    dvr: s.dvr !== false,
    registrationLimit: s.registration_limit || null,
    registrationNote: s.registration_note || '',
    recordingVideoId: s.recording_video_id,
    recordingShortId: s.recording_short_id,
    thumbnailUrl: mediaUrl(s.thumbnail_path),
    scheduledAt: s.scheduled_at,
    startedAt: s.started_at,
    endedAt: s.ended_at,
    viewerCount: s.viewer_count || 0,
    viewerPeak: s.viewer_peak || 0,
    totalViews: s.total_views || 0,
    categoryId: s.category_id,
    sourceProtocol: s.source_protocol,
    createdAt: s.created_at,
    qaEnabled: s.qa_enabled !== false,
    pollsEnabled: s.polls_enabled !== false,
    // Вебинар: гость без учётной записи отмечает присутствие через страницу вебинара (1.12)
    webinar: !!s.webinar,
    webinarSlug: s.webinar ? (s.slug || s.short_id) : null,
    owner: s.owner_name !== undefined ? ownerFromRow(s) : { id: s.owner_id },
    streamKey: withKey && (isOwner || viewer?.role === 'admin') ? s.stream_key : undefined,
    isOwner: !!isOwner,
  };
}

export function notificationOut(n) {
  return {
    // image хранится «голым» путём /media/… (его же склеивают с BASE_URL для писем) — префикс только здесь.
    // link — путь маршрутизатора SPA: префикс к нему добавляет сам маршрутизатор (base истории).
    id: n.id, type: n.type, title: n.title, body: n.body, link: n.link, image: storedMediaUrl(n.image),
    data: n.data || {}, readAt: n.read_at, createdAt: n.created_at,
  };
}

export function categoryOut(c) {
  return { id: c.id, slug: c.slug, name: c.name, description: c.description || '', icon: c.icon, sortOrder: c.sort_order, videoCount: c.video_count || 0, isActive: c.is_active };
}

export { mediaUrl, storage };
