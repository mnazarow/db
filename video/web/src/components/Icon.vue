<script>
import * as mdi from '@mdi/js';

// Имя → путь иконки (Material Design Icons)
export const ICONS = {
  home: mdi.mdiHomeOutline, homeFill: mdi.mdiHome, trending: mdi.mdiFire, subscriptions: mdi.mdiYoutubeSubscription, live: mdi.mdiAccessPoint,
  shorts: mdi.mdiLightningBolt, history: mdi.mdiHistory, watchLater: mdi.mdiClockOutline, thumbUp: mdi.mdiThumbUpOutline, thumbUpFill: mdi.mdiThumbUp,
  thumbDown: mdi.mdiThumbDownOutline, thumbDownFill: mdi.mdiThumbDown, playlist: mdi.mdiPlaylistPlay, playlistAdd: mdi.mdiPlaylistPlus, library: mdi.mdiVideoBox,
  search: mdi.mdiMagnify, upload: mdi.mdiTrayArrowUp, bell: mdi.mdiBellOutline, bellFill: mdi.mdiBell, bellRing: mdi.mdiBellRing, bellOff: mdi.mdiBellOff,
  account: mdi.mdiAccountCircle, menu: mdi.mdiMenu, close: mdi.mdiClose, more: mdi.mdiDotsVertical, moreH: mdi.mdiDotsHorizontal, share: mdi.mdiShareVariant,
  download: mdi.mdiDownload, flag: mdi.mdiFlag, edit: mdi.mdiPencil, delete: mdi.mdiDelete, eye: mdi.mdiEyeOutline, eyeOff: mdi.mdiEyeOff, lock: mdi.mdiLock,
  link: mdi.mdiLink, earth: mdi.mdiEarth, accountGroup: mdi.mdiAccountGroup, settings: mdi.mdiCogOutline, dashboard: mdi.mdiViewDashboard,
  analytics: mdi.mdiChartLine, chartBar: mdi.mdiChartBar, comment: mdi.mdiCommentTextOutline, check: mdi.mdiCheck, checkAll: mdi.mdiCheckAll, alert: mdi.mdiAlert,
  alertCircle: mdi.mdiAlertCircle, info: mdi.mdiInformationOutline, play: mdi.mdiPlay, pause: mdi.mdiPause, next: mdi.mdiSkipNext, prev: mdi.mdiSkipPrevious,
  volumeHigh: mdi.mdiVolumeHigh, volumeMed: mdi.mdiVolumeMedium, volumeOff: mdi.mdiVolumeOff, fullscreen: mdi.mdiFullscreen, fullscreenExit: mdi.mdiFullscreenExit,
  pip: mdi.mdiPictureInPictureBottomRight, theater: mdi.mdiRectangleOutline, captions: mdi.mdiClosedCaptionOutline, captionsFill: mdi.mdiClosedCaption,
  speed: mdi.mdiSpeedometer, tune: mdi.mdiTune, replay: mdi.mdiReplay, loop: mdi.mdiRepeat, fwd10: mdi.mdiFastForward10, back10: mdi.mdiRewind10,
  chevronLeft: mdi.mdiChevronLeft, chevronRight: mdi.mdiChevronRight, chevronDown: mdi.mdiChevronDown, chevronUp: mdi.mdiChevronUp, back: mdi.mdiArrowLeft,
  arrowRight: mdi.mdiArrowRight, plus: mdi.mdiPlus, minus: mdi.mdiMinus, sort: mdi.mdiSortVariant, filter: mdi.mdiFilterVariant, calendar: mdi.mdiCalendar,
  folder: mdi.mdiFolder, school: mdi.mdiSchool, inventory: mdi.mdiPackageVariant, event: mdi.mdiCalendarStar, campaign: mdi.mdiBullhorn, menu_book: mdi.mdiBookOpenVariant,
  star: mdi.mdiStar, starOutline: mdi.mdiStarOutline, videocam: mdi.mdiVideo, key: mdi.mdiKey, mail: mdi.mdiEmail, shield: mdi.mdiShieldCheck, admin: mdi.mdiShieldAccount,
  logout: mdi.mdiLogout, login: mdi.mdiLogin, sun: mdi.mdiWeatherSunny, moon: mdi.mdiWeatherNight, copy: mdi.mdiContentCopy, qr: mdi.mdiQrcode, code: mdi.mdiCodeTags,
  pin: mdi.mdiPin, heart: mdi.mdiHeart, heartOutline: mdi.mdiHeartOutline, cancel: mdi.mdiCancel, verified: mdi.mdiCheckDecagram, personAdd: mdi.mdiAccountPlus,
  person: mdi.mdiAccount, database: mdi.mdiDatabase, memory: mdi.mdiMemory, refresh: mdi.mdiRefresh, openNew: mdi.mdiOpenInNew, drag: mdi.mdiDragVertical,
  image: mdi.mdiImage, subtitles: mdi.mdiSubtitles, mic: mdi.mdiMicrophone, camera: mdi.mdiCamera, stop: mdi.mdiStop, send: mdi.mdiSend, timer: mdi.mdiTimerOutline,
  help: mdi.mdiHelpCircle, keyboard: mdi.mdiKeyboardOutline, transcript: mdi.mdiTextBoxOutline, desktop: mdi.mdiMonitor, mobile: mdi.mdiCellphone, tablet: mdi.mdiTablet, tv: mdi.mdiTelevision, castTv: mdi.mdiCastVariant, list: mdi.mdiFormatListBulleted,
  grid: mdi.mdiViewGrid, videoPlus: mdi.mdiVideoPlus, movie: mdi.mdiMovieOpen, server: mdi.mdiServer, clipboard: mdi.mdiClipboardText, textSearch: mdi.mdiTextBoxSearch,
  translate: mdi.mdiTranslate, robot: mdi.mdiRobot, lan: mdi.mdiLan, emailFast: mdi.mdiEmailFast, palette: mdi.mdiPalette, broadcast: mdi.mdiBroadcast, domain: mdi.mdiDomain,
  tag: mdi.mdiTag, bookmark: mdi.mdiBookmarkOutline, bookmarkFill: mdi.mdiBookmark, timeline: mdi.mdiChartTimelineVariant, harddisk: mdi.mdiHarddisk, cpu: mdi.mdiCpu64Bit,
  wrench: mdi.mdiWrench, listStatus: mdi.mdiListStatus, accountClock: mdi.mdiAccountClock, accountCheck: mdi.mdiAccountCheck, accountCancel: mdi.mdiAccountCancel,
  accountOff: mdi.mdiAccountOff, gavel: mdi.mdiGavel, shieldAlert: mdi.mdiShieldAlert, fileVideo: mdi.mdiFileVideo, donut: mdi.mdiChartDonut, webcam: mdi.mdiWebcam,
  accounts: mdi.mdiAccountMultiple, playCircle: mdi.mdiPlayCircleOutline, lockOpen: mdi.mdiLockOpenVariant, phoneKey: mdi.mdiCellphoneKey, apps: mdi.mdiApps,
  collapse: mdi.mdiChevronDoubleLeft, expand: mdi.mdiChevronDoubleRight, doc: mdi.mdiFileDocument, cut: mdi.mdiScissorsCutting, dots: mdi.mdiDotsCircle,
  motion: mdi.mdiMotionPlay, monitorShare: mdi.mdiMonitor,
  // 1.1: корпоративные функции
  groups: mdi.mdiAccountGroupOutline, assignment: mdi.mdiClipboardCheckOutline, assignmentFill: mdi.mdiClipboardCheck, quiz: mdi.mdiHelpBox, quizOutline: mdi.mdiHelpBoxOutline,
  note: mdi.mdiNoteEditOutline, noteFill: mdi.mdiNoteEdit, linkLock: mdi.mdiLinkLock, watermark: mdi.mdiWatermark, swap: mdi.mdiSwapHorizontal, stethoscope: mdi.mdiStethoscope, broom: mdi.mdiBroom, toggles: mdi.mdiToggleSwitchOutline, sparkles: mdi.mdiCreation,
  record: mdi.mdiRecordRec, screenShare: mdi.mdiMonitorShare, importUrl: mdi.mdiCloudDownloadOutline, csv: mdi.mdiFileDelimitedOutline, clock: mdi.mdiClockAlertOutline,
  people: mdi.mdiAccountEyeOutline, install: mdi.mdiCellphoneArrowDown, history2: mdi.mdiBackupRestore, done: mdi.mdiCheckCircle, doneOutline: mdi.mdiCheckCircleOutline,
  progress: mdi.mdiProgressClock, chapters: mdi.mdiBookmarkMultipleOutline, lightbulb: mdi.mdiLightbulbOnOutline, clipboardList: mdi.mdiClipboardListOutline,
  // 1.3
  scissors: mdi.mdiContentCut, movieEdit: mdi.mdiMovieOpenEdit, cards: mdi.mdiCardTextOutline, webhook: mdi.mdiWebhook, rss: mdi.mdiRss, podcast: mdi.mdiPodcast,
  ocr: mdi.mdiTextRecognition, headphones: mdi.mdiHeadphones, chatReplay: mdi.mdiMessageTextClock, folderSync: mdi.mdiFolderSync, newsletter: mdi.mdiEmailNewsletter,
  scorm: mdi.mdiZipBox, waveform: mdi.mdiWaveform, mute: mdi.mdiVolumeVariantOff, queue: mdi.mdiPlaylistPlus, queueList: mdi.mdiPlaylistPlay, endScreen: mdi.mdiViewCarousel, api: mdi.mdiApi,
};

export default {
  name: 'Icon',
  props: {
    name: { type: String, required: true },
    size: { type: [Number, String], default: 24 },
  },
  computed: {
    path() { return ICONS[this.name] || mdi.mdiHelpCircleOutline; },
  },
};
</script>

<template>
  <svg class="icon" :width="size" :height="size" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path :d="path" /></svg>
</template>

<style>
.icon { flex-shrink: 0; }
</style>
