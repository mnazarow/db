// Раскладка кадра при записи экрана и камеры: врезка в углу и вписывание «по большей стороне».

/** Какую часть источника взять, чтобы заполнить прямоугольник без искажений (как object-fit: cover). */
export function coverCrop(srcW, srcH, dstW, dstH) {
  const w = Math.max(1, srcW), h = Math.max(1, srcH);
  const scale = Math.max(dstW / w, dstH / h);
  const sw = Math.min(w, dstW / scale);
  const sh = Math.min(h, dstH / scale);
  return { sx: (w - sw) / 2, sy: (h - sh) / 2, sw, sh };
}

/** Доля ширины кадра под врезку: маленькая, обычная, крупная. */
export function insetFraction(size) {
  return { sm: 0.16, md: 0.22, lg: 0.3 }[size] ?? 0.22;
}

/** Прямоугольник врезки в углу кадра: pos — tl | tr | bl | br; ratio — высота к ширине (1 для круга). */
export function insetRect(canvasW, canvasH, pos = 'br', widthFraction = 0.22, margin = 24, ratio = 9 / 16) {
  const w = Math.round(canvasW * Math.min(0.6, Math.max(0.08, widthFraction)));
  const h = Math.round(w * ratio);
  const m = Math.round(Math.min(margin, canvasW * 0.05));
  const x = String(pos).endsWith('r') ? canvasW - w - m : m;
  const y = String(pos).startsWith('b') ? canvasH - h - m : m;
  return { x, y, w, h };
}

/** Что показывать крупно, а что во врезке, при выбранной раскладке. */
export function layoutSources(layout, { screen, camera }) {
  if (layout === 'cam-main') return { main: camera, inset: screen };
  return { main: screen, inset: camera };
}
