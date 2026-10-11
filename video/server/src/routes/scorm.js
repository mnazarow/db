// Экспорт видео в SCORM-пакет (1.2 или 2004) для LMS: Moodle, iSpring Learn, WebTutor, Mirapolis, Эквио.
// Пакет содержит обёртку, которая встраивает плеер портала и передаёт прогресс просмотра в LMS через SCORM API.
import { one } from '../db.js';
import { badRequest } from '../lib/util.js';
import { audit } from '../lib/audit.js';
import { randomToken } from '../lib/crypto.js';
import { zipBuffer } from '../lib/zip.js';
import { config } from '../config.js';
import { requireEditable } from './videos.js';

// Управляющие символы недопустимы в XML — иначе imsmanifest.xml не примет ни одна СДО.
const XML_FORBIDDEN = /[\u0000-\u0008\u000B\u000C\u000E-\u001F\uFFFE\uFFFF]|[\uD800-\uDBFF](?![\uDC00-\uDFFF])|(?<![\uD800-\uDBFF])[\uDC00-\uDFFF]/g;
const esc = (s) => String(s ?? '').replace(XML_FORBIDDEN, '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

function manifest12({ id, title }) {
  return `<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="corpvideo-${id}" version="1.0"
  xmlns="http://www.imsproject.org/xsd/imscp_rootv1p1p2" xmlns:adlcp="http://www.adlnet.org/xsd/adlcp_rootv1p2"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="http://www.imsproject.org/xsd/imscp_rootv1p1p2 imscp_rootv1p1p2.xsd http://www.imsglobal.org/xsd/imsmd_rootv1p2p1 imsmd_rootv1p2p1.xsd http://www.adlnet.org/xsd/adlcp_rootv1p2 adlcp_rootv1p2.xsd">
  <metadata><schema>ADL SCORM</schema><schemaversion>1.2</schemaversion></metadata>
  <organizations default="org">
    <organization identifier="org"><title>${esc(title)}</title>
      <item identifier="item1" identifierref="res1" isvisible="true"><title>${esc(title)}</title></item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="res1" type="webcontent" adlcp:scormtype="sco" href="index.html">
      <file href="index.html"/><file href="scorm.js"/>
    </resource>
  </resources>
</manifest>`;
}

function manifest2004({ id, title }) {
  return `<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="corpvideo-${id}" version="1"
  xmlns="http://www.imsglobal.org/xsd/imscp_v1p1" xmlns:adlcp="http://www.adlnet.org/xsd/adlcp_v1p3"
  xmlns:adlseq="http://www.adlnet.org/xsd/adlseq_v1p3" xmlns:adlnav="http://www.adlnet.org/xsd/adlnav_v1p3" xmlns:imsss="http://www.imsglobal.org/xsd/imsss"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <metadata><schema>ADL SCORM</schema><schemaversion>2004 4th Edition</schemaversion></metadata>
  <organizations default="org">
    <organization identifier="org"><title>${esc(title)}</title>
      <item identifier="item1" identifierref="res1"><title>${esc(title)}</title></item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="res1" type="webcontent" adlcp:scormType="sco" href="index.html"><file href="index.html"/><file href="scorm.js"/></resource>
  </resources>
</manifest>`;
}

const SCORM_JS = `// Обёртка SCORM 1.2 / 2004: поиск API в родительских окнах, единый интерфейс.
(function (global) {
  function findApi(win, name) {
    var tries = 0;
    while (win && tries < 30) {
      try { if (win[name]) return win[name]; } catch (e) {}
      if (win.parent && win.parent !== win) { win = win.parent; tries++; } else break;
    }
    return null;
  }
  function locate(name) {
    var api = findApi(window, name);
    if (!api && window.opener) api = findApi(window.opener, name);
    return api;
  }
  var api12 = locate('API'), api2004 = locate('API_1484_11');
  var scorm = { version: api2004 ? '2004' : (api12 ? '1.2' : null), api: api2004 || api12, ok: false };
  scorm.init = function () {
    if (!scorm.api) return false;
    try { scorm.ok = (scorm.version === '2004' ? scorm.api.Initialize('') : scorm.api.LMSInitialize('')) === 'true'; } catch (e) { scorm.ok = false; }
    return scorm.ok;
  };
  scorm.get = function (k12, k2004) {
    if (!scorm.ok) return '';
    try { return scorm.version === '2004' ? scorm.api.GetValue(k2004 || k12) : scorm.api.LMSGetValue(k12); } catch (e) { return ''; }
  };
  scorm.set = function (k12, k2004, v) {
    if (!scorm.ok) return false;
    try { return (scorm.version === '2004' ? scorm.api.SetValue(k2004 || k12, String(v)) : scorm.api.LMSSetValue(k12, String(v))) === 'true'; } catch (e) { return false; }
  };
  scorm.commit = function () { if (!scorm.ok) return; try { scorm.version === '2004' ? scorm.api.Commit('') : scorm.api.LMSCommit(''); } catch (e) {} };
  scorm.finish = function () { if (!scorm.ok) return; try { scorm.version === '2004' ? scorm.api.Terminate('') : scorm.api.LMSFinish(''); } catch (e) {} scorm.ok = false; };
  scorm.setStatus = function (status) {
    // status: incomplete | completed | passed | failed
    if (scorm.version === '2004') {
      scorm.set(null, 'cmi.completion_status', status === 'incomplete' ? 'incomplete' : 'completed');
      if (status === 'passed' || status === 'failed') scorm.set(null, 'cmi.success_status', status);
    } else scorm.set('cmi.core.lesson_status', null, status);
  };
  scorm.setScore = function (percent) {
    if (scorm.version === '2004') { scorm.set(null, 'cmi.score.scaled', (percent / 100).toFixed(2)); scorm.set(null, 'cmi.score.raw', percent); scorm.set(null, 'cmi.score.min', 0); scorm.set(null, 'cmi.score.max', 100); }
    else { scorm.set('cmi.core.score.raw', null, percent); scorm.set('cmi.core.score.min', null, 0); scorm.set('cmi.core.score.max', null, 100); }
  };
  scorm.setLocation = function (v) { scorm.set('cmi.core.lesson_location', 'cmi.location', v); };
  scorm.getLocation = function () { return scorm.get('cmi.core.lesson_location', 'cmi.location'); };
  global.CorpVideoScorm = scorm;
})(window);
`;

function indexHtml({ title, embedUrl, requiredPercent, siteName }) {
  return `<!DOCTYPE html>
<html lang="ru"><head><meta charset="utf-8"><title>${esc(title)}</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>html,body{margin:0;height:100%;background:#000;font-family:Arial,sans-serif}#frame{position:absolute;inset:0;width:100%;height:100%;border:0}#status{position:absolute;left:0;right:0;bottom:0;padding:6px 12px;background:rgba(15,67,130,.9);color:#fff;font-size:13px;display:flex;justify-content:space-between;gap:12px;opacity:.95}</style>
<script src="scorm.js"></script>
</head><body>
<iframe id="frame" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe>
<div id="status"><span id="st">Подключение к системе обучения…</span><span>${esc(siteName)}</span></div>
<script>
(function () {
  var REQUIRED = ${Number(requiredPercent) || 90};
  var s = window.CorpVideoScorm, st = document.getElementById('st'), frame = document.getElementById('frame');
  var done = false, best = 0, lastCommit = 0;
  var ok = s.init();
  var startAt = 0;
  if (ok) { var loc = parseFloat(s.getLocation()); if (loc > 0) startAt = loc; var prev = s.get('cmi.core.lesson_status', 'cmi.completion_status'); if (prev === 'completed' || prev === 'passed') done = true; if (!done) s.setStatus('incomplete'); s.commit(); }
  st.textContent = ok ? ('SCORM ' + s.version + ' · для зачёта нужно посмотреть ' + REQUIRED + '%') : 'LMS не найдена — прогресс не сохраняется';
  var url = ${JSON.stringify(embedUrl)};
  var portalOrigin = (function () { try { return new URL(url, location.href).origin; } catch (err) { return null; } })();
  frame.src = url + (startAt ? '&t=' + Math.floor(startAt) : '');
  window.addEventListener('message', function (e) {
    // Сообщения о прогрессе принимаем только от нашего плеера: иначе любая страница в СДО «зачла» бы курс
    if (e.source !== frame.contentWindow) return;
    if (portalOrigin && e.origin !== portalOrigin) return;
    var m = e.data || {};
    if (m.source !== 'corpvideo') return;
    if (m.event === 'progress' || m.event === 'ended') {
      var pct = Math.round(m.percent || 0);
      if (pct > best) best = pct;
      if (ok) {
        s.setLocation(Math.floor(m.position || 0));
        s.setScore(best);
        if (!done && (best >= REQUIRED || m.event === 'ended')) { done = true; s.setStatus('completed'); }
        var now = Date.now(); if (now - lastCommit > 15000 || m.event === 'ended') { lastCommit = now; s.commit(); }
      }
      st.textContent = 'Просмотрено ' + best + '%' + (done ? ' · зачтено' : ' · для зачёта нужно ' + REQUIRED + '%') + (ok ? '' : ' (LMS не найдена)');
    }
  });
  window.addEventListener('beforeunload', function () { if (ok) { s.commit(); s.finish(); } });
})();
</script>
</body></html>`;
}

export default async function scormRoutes(app) {
  app.get('/videos/:id/scorm.zip', { preHandler: app.requireActive }, async (req, reply) => {
    const v = await requireEditable(req, req.params.id);
    if (v.status !== 'ready') throw badRequest('Видео ещё обрабатывается');
    const version = req.query.version === '2004' ? '2004' : '1.2';
    const percent = Math.min(100, Math.max(10, Number(req.query.percent) || Number(req.settings['assignments.default_percent']) || 90));
    let share = null;
    if (req.query.share === '1') {
      // гостевая ссылка без пароля и срока — чтобы слушатели LMS не входили на портал
      share = await one(`SELECT token FROM share_links WHERE video_id = $1 AND note = 'SCORM' AND revoked_at IS NULL AND password_hash IS NULL AND expires_at IS NULL LIMIT 1`, [v.id]);
      if (!share) share = await one(`INSERT INTO share_links(video_id, token, note, allow_download, created_by) VALUES ($1,$2,'SCORM',false,$3) RETURNING token`, [v.id, randomToken(18), req.user.id]);
    }
    const embedUrl = `${config.baseUrl}/embed/${v.short_id}?api=1&scorm=1${share ? `&share=${share.token}` : ''}`;
    const siteName = req.settings['site.name'] || 'CorpVideo';
    const files = [
      { name: 'imsmanifest.xml', data: version === '2004' ? manifest2004({ id: v.short_id, title: v.title }) : manifest12({ id: v.short_id, title: v.title }) },
      { name: 'index.html', data: indexHtml({ title: v.title, embedUrl, requiredPercent: percent, siteName }) },
      { name: 'scorm.js', data: SCORM_JS },
      { name: 'README.txt', data: `SCORM ${version} пакет видео «${v.title}» с портала ${siteName} (${config.baseUrl}/watch/${v.short_id}).\nЗагрузите zip в LMS как SCORM-курс. Плеер открывается с портала; зачёт выставляется при просмотре ${percent}% видео.\n${share ? 'Доступ к видео — по гостевой ссылке, вход на портал не требуется.' : 'Слушатели должны быть авторизованы на портале (видео для сотрудников) либо видео должно быть публичным.'}\n` },
    ];
    const buf = zipBuffer(files);
    await audit(req, 'video.scorm_export', { targetType: 'video', targetId: v.id, details: { version, percent, share: !!share } });
    const safe = String(v.title).replace(/[\\/:*?"<>|\r\n]+/g, '_').slice(0, 80) || 'video';
    reply.header('Content-Type', 'application/zip');
    reply.header('Content-Disposition', `attachment; filename*=UTF-8''${encodeURIComponent(`${safe}-scorm${version === '2004' ? '2004' : '12'}.zip`)}`);
    return reply.send(buf);
  });
}
