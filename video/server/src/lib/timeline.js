// Графический видеоредактор: проект монтажа (куски, титры, картинки, музыка) → граф фильтров ffmpeg.
// Проект хранится в JSON; время кусков — по исходнику, время титров/картинок/музыки — по готовому ролику.
import path from 'node:path';
import fs from 'node:fs';

export const ASPECTS = ['source', '16:9', '9:16', '1:1', '4:5'];
export const TRANSITIONS = { none: null, dissolve: 'fade', fadeblack: 'fadeblack', slide: 'slideleft', wipe: 'wiperight' };
const FONT_CANDIDATES = [
  '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
  '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
  '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
  '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
  '/System/Library/Fonts/Supplemental/Arial.ttf',
];

export function findFont() {
  for (const f of FONT_CANDIDATES) { try { if (fs.existsSync(f)) return f; } catch { /* ignore */ } }
  return null;
}

const num = (v, def = 0) => (Number.isFinite(Number(v)) ? Number(v) : def);
const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
const even = (n) => Math.max(2, Math.round(n / 2) * 2);
const r2 = (n) => Math.round(n * 1000) / 1000;
const color = (c, def = 'white') => (/^#[0-9a-fA-F]{6}$/.test(String(c || '')) ? `0x${String(c).slice(1)}` : def);

/** Проверка и приведение проекта к каноническому виду. */
export function normalizeProject(raw, { duration = 0, assets = [] } = {}) {
  const p = raw && typeof raw === 'object' ? raw : {};
  const imgIds = new Set(assets.filter((a) => a.kind === 'image').map((a) => a.id));
  const audIds = new Set(assets.filter((a) => a.kind === 'audio').map((a) => a.id));
  const clips = (Array.isArray(p.clips) ? p.clips : [])
    .map((c, i) => {
      const start = clamp(num(c.start), 0, duration || Infinity);
      const end = clamp(num(c.end, duration), 0, duration || Infinity);
      return {
        id: String(c.id || `c${i + 1}`).slice(0, 40),
        start: r2(start), end: r2(end),
        speed: clamp(num(c.speed, 1), 0.5, 2),
        volume: clamp(num(c.volume, 1), 0, 2),
        transition: TRANSITIONS[c.transition?.type] !== undefined && c.transition?.type !== 'none'
          ? { type: c.transition.type, duration: clamp(num(c.transition.duration, 0.5), 0.1, 3) }
          : { type: 'none', duration: 0 },
      };
    })
    .filter((c) => c.end - c.start >= 0.2);
  if (!clips.length && duration) clips.push({ id: 'c1', start: 0, end: r2(duration), speed: 1, volume: 1, transition: { type: 'none', duration: 0 } });
  const total = clips.reduce((n, c) => n + (c.end - c.start) / c.speed, 0)
    - clips.slice(0, -1).reduce((n, c) => n + (c.transition.type !== 'none' ? c.transition.duration : 0), 0);
  const outDur = Math.max(0.5, r2(total));
  const texts = (Array.isArray(p.texts) ? p.texts : []).slice(0, 40).map((t, i) => ({
    id: String(t.id || `t${i + 1}`).slice(0, 40),
    text: String(t.text ?? '').slice(0, 300),
    start: r2(clamp(num(t.start), 0, outDur)),
    end: r2(clamp(num(t.end, outDur), 0.2, outDur)),
    x: clamp(num(t.x, 0.5), 0, 1), y: clamp(num(t.y, 0.82), 0, 1),
    size: clamp(Math.round(num(t.size, 48)), 10, 200),
    color: /^#[0-9a-fA-F]{6}$/.test(t.color || '') ? t.color : '#ffffff',
    bg: t.bg === null || t.bg === '' ? null : (/^#[0-9a-fA-F]{6}$/.test(t.bg || '') ? t.bg : '#000000'),
    bgOpacity: clamp(num(t.bgOpacity, 0.55), 0, 1),
    fade: clamp(num(t.fade, 0.3), 0, 2),
  })).filter((t) => t.text.trim() && t.end > t.start);
  const images = (Array.isArray(p.images) ? p.images : []).slice(0, 20).map((im, i) => ({
    id: String(im.id || `i${i + 1}`).slice(0, 40),
    assetId: im.assetId,
    start: r2(clamp(num(im.start), 0, outDur)),
    end: r2(clamp(num(im.end, outDur), 0.2, outDur)),
    x: clamp(num(im.x, 0.85), 0, 1), y: clamp(num(im.y, 0.08), 0, 1),
    width: clamp(num(im.width, 0.18), 0.02, 1),
    opacity: clamp(num(im.opacity, 1), 0.05, 1),
  })).filter((im) => imgIds.has(im.assetId) && im.end > im.start);
  const audio = (Array.isArray(p.audio) ? p.audio : []).slice(0, 8).map((a, i) => ({
    id: String(a.id || `a${i + 1}`).slice(0, 40),
    assetId: a.assetId,
    start: r2(clamp(num(a.start), 0, outDur)),
    end: r2(clamp(num(a.end, outDur), 0.2, outDur)),
    volume: clamp(num(a.volume, 0.35), 0, 2),
    fadeIn: clamp(num(a.fadeIn, 1), 0, 10),
    fadeOut: clamp(num(a.fadeOut, 2), 0, 10),
    offset: Math.max(0, num(a.offset, 0)),          // с какого места звучит сам файл
  })).filter((a) => audIds.has(a.assetId) && a.end > a.start);
  return {
    version: 1,
    aspect: ASPECTS.includes(p.aspect) ? p.aspect : 'source',
    mainVolume: clamp(num(p.mainVolume, 1), 0, 2),
    burnSubtitles: !!p.burnSubtitles,
    subtitleId: p.subtitleId || null,
    fadeIn: clamp(num(p.fadeIn, 0), 0, 5),
    fadeOut: clamp(num(p.fadeOut, 0), 0, 5),
    clips, texts, images, audio,
    duration: outDur,
  };
}

/** Длительность готового ролика. */
export function projectDuration(p) {
  const clips = p.clips || [];
  const sum = clips.reduce((n, c) => n + (c.end - c.start) / (c.speed || 1), 0);
  const tr = clips.slice(0, -1).reduce((n, c) => n + (c.transition?.type && c.transition.type !== 'none' ? c.transition.duration : 0), 0);
  return Math.max(0, r2(sum - tr));
}

/** Время готового ролика → место в исходнике (для предпросмотра и перемотки). */
export function outputToSource(p, t) {
  let acc = 0;
  const clips = p.clips || [];
  for (let i = 0; i < clips.length; i++) {
    const c = clips[i];
    const len = (c.end - c.start) / (c.speed || 1);
    const trPrev = i > 0 && clips[i - 1].transition?.type !== 'none' ? clips[i - 1].transition.duration : 0;
    const startOut = acc - (i > 0 ? trPrev : 0);
    if (t < startOut + len || i === clips.length - 1) {
      const within = Math.max(0, Math.min(len, t - startOut));
      return { index: i, clip: c, source: r2(c.start + within * (c.speed || 1)), speed: c.speed || 1 };
    }
    acc = startOut + len;
  }
  return { index: 0, clip: clips[0] || null, source: 0, speed: 1 };
}

/** Размер кадра на выходе с учётом выбранного формата. */
export function outputSize(meta, aspect) {
  const w = even(meta?.width || 1280), h = even(meta?.height || 720);
  if (aspect === 'source' || !aspect) return { w, h, crop: null };
  const target = { '16:9': 16 / 9, '9:16': 9 / 16, '1:1': 1, '4:5': 4 / 5 }[aspect];
  if (!target) return { w, h, crop: null };
  const cur = w / h;
  let cw = w, ch = h;
  if (cur > target) cw = even(h * target); else ch = even(w / target);
  // Ограничиваем высоту 1920 точками, чтобы не раздувать файл
  let ow = cw, oh = ch;
  if (oh > 1920) { ow = even(cw * (1920 / oh)); oh = 1920; }
  return { w: ow, h: oh, crop: { w: cw, h: ch, x: `(iw-${cw})/2`, y: `(ih-${ch})/2` } };
}

/** Сдвиг реплик субтитров под смонтированный таймлайн. */
export function remapCues(cues, clips) {
  const out = [];
  let acc = 0;
  for (const c of clips) {
    const len = (c.end - c.start) / (c.speed || 1);
    for (const cue of cues) {
      const s = Math.max(cue.start, c.start), e = Math.min(cue.end, c.end);
      if (e - s < 0.15) continue;
      out.push({
        start: r2(acc + (s - c.start) / (c.speed || 1)),
        end: r2(acc + (e - c.start) / (c.speed || 1)),
        text: cue.text,
      });
    }
    acc += len - (c.transition?.type && c.transition.type !== 'none' ? c.transition.duration : 0);
  }
  return out.sort((a, b) => a.start - b.start);
}

const esc = (s) => String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/:/g, '\\:');

/** Накладки (титры и картинки) поверх готовой картинки. */
function overlayChain(p, { size, assets, inputIndexOf, fontFile, textFiles, still = false, at = null }) {
  const parts = [];
  let cur = '[base]';
  let n = 0;
  for (const im of p.images) {
    const a = assets.find((x) => x.id === im.assetId);
    if (!a) continue;
    const idx = inputIndexOf(a);
    if (idx == null) continue;
    if (still && at != null && (at < im.start || at > im.end)) continue;
    const px = even(size.w * im.width);
    parts.push(`[${idx}:v]scale=${px}:-2,format=rgba,colorchannelmixer=aa=${im.opacity}[img${n}]`);
    const enable = still ? '' : `:enable='between(t,${im.start},${im.end})'`;
    parts.push(`${cur}[img${n}]overlay=x=(main_w-overlay_w)*${im.x}:y=(main_h-overlay_h)*${im.y}${enable}[ov${n}]`);
    cur = `[ov${n}]`;
    n += 1;
  }
  for (const t of p.texts) {
    if (still && at != null && (at < t.start || at > t.end)) continue;
    const file = textFiles.get(t.id);
    if (!file) continue;
    const opts = [
      fontFile ? `fontfile='${esc(fontFile)}'` : `font='Sans'`,
      `textfile='${esc(file)}'`,
      'reload=0',
      `fontsize=${t.size}`,
      `fontcolor=${color(t.color)}`,
      `x=(w-text_w)*${t.x}`,
      `y=(h-text_h)*${t.y}`,
      'line_spacing=6',
    ];
    if (t.bg) opts.push('box=1', `boxcolor=${color(t.bg, 'black')}@${t.bgOpacity}`, 'boxborderw=16');
    if (!still) {
      opts.push(`enable='between(t,${t.start},${t.end})'`);
      if (t.fade > 0.05) {
        const f = t.fade;
        opts.push(`alpha='if(lt(t,${r2(t.start + f)}),max(0\\,(t-${t.start})/${f}),if(lt(t,${r2(t.end - f)}),1,max(0\\,(${t.end}-t)/${f})))'`);
      }
    }
    parts.push(`${cur}drawtext=${opts.join(':')}[tx${n}]`);
    cur = `[tx${n}]`;
    n += 1;
  }
  return { parts, label: cur };
}

/**
 * Полный граф сборки. Возвращает { args, files, duration, size }.
 * files — временные файлы с текстом титров, их пишет вызывающая сторона.
 */
export function buildRender(p, { src, out, meta, assets = [], subtitlesFile = null, fontFile = null, crf = 20, tmpDir = '/tmp' } = {}) {
  const size = outputSize(meta, p.aspect);
  const hasAudio = !!meta?.hasAudio;
  const inputs = ['-i', src];
  const inputMap = new Map();         // asset.id → индекс входа
  let idx = 1;
  for (const im of p.images) {
    const a = assets.find((x) => x.id === im.assetId);
    if (a && !inputMap.has(a.id)) { inputs.push('-i', a.file); inputMap.set(a.id, idx++); }
  }
  for (const au of p.audio) {
    const a = assets.find((x) => x.id === au.assetId);
    if (a && !inputMap.has(a.id)) { inputs.push('-i', a.file); inputMap.set(a.id, idx++); }
  }
  const parts = [];
  const vSeg = [], aSeg = [];
  // Единая частота кадров и шкала времени: без этого xfade отказывается сшивать куски
  const fps = Math.max(10, Math.min(60, Math.round(num(meta?.fps, 25)) || 25));
  p.clips.forEach((c, i) => {
    const sp = c.speed || 1;
    parts.push(`[0:v]trim=start=${c.start}:end=${c.end},setpts=(PTS-STARTPTS)/${sp},fps=${fps},settb=AVTB[cv${i}]`);
    vSeg.push(`[cv${i}]`);
    if (hasAudio) {
      const tempo = sp === 1 ? '' : `,atempo=${sp}`;
      parts.push(`[0:a]atrim=start=${c.start}:end=${c.end},asetpts=PTS-STARTPTS${tempo},volume=${c.volume}[ca${i}]`);
      aSeg.push(`[ca${i}]`);
    }
  });

  // Группы без переходов склеиваем concat, между группами — xfade
  const groups = [];
  let cur = [0];
  for (let i = 0; i < p.clips.length - 1; i++) {
    if (p.clips[i].transition.type === 'none') cur.push(i + 1);
    else { groups.push({ idx: cur, transition: p.clips[i].transition }); cur = [i + 1]; }
  }
  groups.push({ idx: cur, transition: null });

  const groupLabels = [];
  groups.forEach((g, gi) => {
    const dur = g.idx.reduce((n, i) => n + (p.clips[i].end - p.clips[i].start) / (p.clips[i].speed || 1), 0);
    if (g.idx.length === 1) {
      groupLabels.push({ v: vSeg[g.idx[0]], a: hasAudio ? aSeg[g.idx[0]] : null, dur, transition: g.transition });
    } else {
      // Куски без перехода склеиваются concat: чередование [v][a][v][a]…
      const inter = g.idx.map((i) => `${vSeg[i]}${hasAudio ? aSeg[i] : ''}`).join('');
      parts.push(`${inter}concat=n=${g.idx.length}:v=1:a=${hasAudio ? 1 : 0}[gv${gi}]${hasAudio ? `[ga${gi}]` : ''}`);
      groupLabels.push({ v: `[gv${gi}]`, a: hasAudio ? `[ga${gi}]` : null, dur, transition: g.transition });
    }
  });

  if (groupLabels.length > 1) {
    // Перед xfade приводим все группы к одной шкале времени
    groupLabels.forEach((g, i) => {
      parts.push(`${g.v}settb=AVTB,fps=${fps}[gn${i}]`);
      g.v = `[gn${i}]`;
      if (g.a) { parts.push(`${g.a}aresample=async=1:first_pts=0[gna${i}]`); g.a = `[gna${i}]`; }
    });
  }
  let vLabel = groupLabels[0].v, aLabel = groupLabels[0].a, acc = groupLabels[0].dur;
  for (let i = 1; i < groupLabels.length; i++) {
    const tr = groups[i - 1].transition;
    const d = tr.duration;
    const kind = TRANSITIONS[tr.type] || 'fade';
    const offset = r2(Math.max(0, acc - d));
    parts.push(`${vLabel}${groupLabels[i].v}xfade=transition=${kind}:duration=${d}:offset=${offset}[xv${i}]`);
    vLabel = `[xv${i}]`;
    if (hasAudio) {
      parts.push(`${aLabel}${groupLabels[i].a}acrossfade=d=${d}:c1=tri:c2=tri[xa${i}]`);
      aLabel = `[xa${i}]`;
    }
    acc = r2(acc + groupLabels[i].dur - d);
  }
  const duration = r2(acc);

  // Формат кадра
  const vf = [];
  if (size.crop) vf.push(`crop=${size.crop.w}:${size.crop.h}:${size.crop.x}:${size.crop.y}`);
  if (size.crop && (size.w !== size.crop.w || size.h !== size.crop.h)) vf.push(`scale=${size.w}:${size.h}`);
  vf.push('setsar=1');
  if (subtitlesFile) vf.push(`subtitles='${esc(subtitlesFile)}':force_style='FontSize=18,Outline=1,Shadow=0'`);
  if (p.fadeIn > 0.05) vf.push(`fade=t=in:st=0:d=${p.fadeIn}`);
  if (p.fadeOut > 0.05) vf.push(`fade=t=out:st=${r2(Math.max(0, duration - p.fadeOut))}:d=${p.fadeOut}`);
  parts.push(`${vLabel}${vf.join(',')}[base]`);

  // Титры и картинки
  const textFiles = new Map();
  const files = [];
  p.texts.forEach((t, i) => {
    const f = path.join(tmpDir, `cv-text-${i}-${Date.now()}.txt`);
    textFiles.set(t.id, f);
    files.push({ path: f, content: t.text });
  });
  const ov = overlayChain(p, { size, assets: assets.map((a) => ({ ...a, idx: inputMap.get(a.id) })), inputIndexOf: (a) => inputMap.get(a.id), fontFile, textFiles });
  parts.push(...ov.parts);
  parts.push(`${ov.label}format=yuv420p[vout]`);

  // Звук: основная дорожка + музыка
  let audioOut = null;
  const musicLabels = [];
  p.audio.forEach((au, i) => {
    const a = assets.find((x) => x.id === au.assetId);
    const ai = a && inputMap.get(a.id);
    if (ai == null) return;
    const len = r2(au.end - au.start);
    const chain = [
      `atrim=start=${au.offset}:end=${r2(au.offset + len)}`,
      'asetpts=PTS-STARTPTS',
      `volume=${au.volume}`,
    ];
    if (au.fadeIn > 0.05) chain.push(`afade=t=in:st=0:d=${au.fadeIn}`);
    if (au.fadeOut > 0.05) chain.push(`afade=t=out:st=${r2(Math.max(0, len - au.fadeOut))}:d=${au.fadeOut}`);
    if (au.start > 0.01) chain.push(`adelay=${Math.round(au.start * 1000)}:all=1`);
    parts.push(`[${ai}:a]${chain.join(',')}[mus${i}]`);
    musicLabels.push(`[mus${i}]`);
  });
  if (hasAudio && aLabel) {
    parts.push(`${aLabel}volume=${p.mainVolume}[amain]`);
    if (musicLabels.length) {
      parts.push(`[amain]${musicLabels.join('')}amix=inputs=${musicLabels.length + 1}:normalize=0:duration=first[aout]`);
      audioOut = '[aout]';
    } else audioOut = '[amain]';
  } else if (musicLabels.length) {
    parts.push(musicLabels.length > 1 ? `${musicLabels.join('')}amix=inputs=${musicLabels.length}:normalize=0[aout]` : `${musicLabels[0]}anull[aout]`);
    audioOut = '[aout]';
  }

  const args = ['-hide_banner', '-nostdin', ...inputs, '-filter_complex', parts.join(';'), '-map', '[vout]'];
  if (audioOut) args.push('-map', audioOut, '-c:a', 'aac', '-b:a', '192k');
  else args.push('-an');
  args.push('-c:v', 'libx264', '-preset', 'veryfast', '-crf', String(crf), '-pix_fmt', 'yuv420p', '-movflags', '+faststart', '-t', String(duration), out);
  return { args, files, duration, size, filter: parts.join(';') };
}

/** Кадр предпросмотра: быстрый — берём момент из исходника и рисуем активные накладки. */
export function buildPreview(p, at, { src, meta, assets = [], fontFile = null, out = 'pipe:1', tmpDir = '/tmp' } = {}) {
  const size = outputSize(meta, p.aspect);
  const map = outputToSource(p, at);
  const inputs = ['-ss', String(map.source), '-i', src];
  const inputMap = new Map();
  let idx = 1;
  for (const im of p.images) {
    if (at < im.start || at > im.end) continue;
    const a = assets.find((x) => x.id === im.assetId);
    if (a && !inputMap.has(a.id)) { inputs.push('-i', a.file); inputMap.set(a.id, idx++); }
  }
  const parts = [];
  const vf = [];
  if (size.crop) vf.push(`crop=${size.crop.w}:${size.crop.h}:${size.crop.x}:${size.crop.y}`);
  if (size.crop && (size.w !== size.crop.w || size.h !== size.crop.h)) vf.push(`scale=${size.w}:${size.h}`);
  vf.push('setsar=1');
  parts.push(`[0:v]${vf.join(',')}[base]`);
  const textFiles = new Map();
  const files = [];
  p.texts.forEach((t, i) => {
    if (at < t.start || at > t.end) return;
    const f = path.join(tmpDir, `cv-pv-${i}-${Date.now()}.txt`);
    textFiles.set(t.id, f);
    files.push({ path: f, content: t.text });
  });
  const ov = overlayChain(p, { size, assets, inputIndexOf: (a) => inputMap.get(a.id), fontFile, textFiles, still: true, at });
  parts.push(...ov.parts);
  parts.push(`${ov.label}format=yuvj420p[vout]`);
  const args = ['-hide_banner', '-nostdin', '-loglevel', 'error', ...inputs, '-filter_complex', parts.join(';'), '-map', '[vout]', '-frames:v', '1', '-q:v', '4', '-f', 'image2', '-c:v', 'mjpeg', out];
  return { args, files, size, source: map.source };
}
