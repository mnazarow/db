#!/usr/bin/env python3
# CorpVideo: поиск лиц в видео для вкладки «Размытие».
# Кадры просматриваются с шагом, найденные лица объединяются в дорожки (одно лицо = одна рамка с отрезком времени).
# Выводит JSON: {"ok":true,"regions":[{"x":..,"y":..,"w":..,"h":..,"start":..,"end":..}]} — координаты в долях кадра.
import json, sys

def fail(msg):
    print(json.dumps({"ok": False, "error": msg}, ensure_ascii=False))
    sys.exit(0)

try:
    import cv2
except Exception:
    fail("Не установлен python3-opencv: поставьте пакет или расставьте рамки вручную")


def iou(a, b):
    ax, ay, aw, ah = a; bx, by, bw, bh = b
    x1, y1 = max(ax, bx), max(ay, by)
    x2, y2 = min(ax + aw, bx + bw), min(ay + ah, by + bh)
    inter = max(0, x2 - x1) * max(0, y2 - y1)
    union = aw * ah + bw * bh - inter
    return inter / union if union > 0 else 0.0


def main():
    src = sys.argv[1]
    step = float(sys.argv[2]) if len(sys.argv) > 2 else 2.0
    max_regions = int(sys.argv[3]) if len(sys.argv) > 3 else 40
    cap = cv2.VideoCapture(src)
    if not cap.isOpened():
        fail("Не удалось открыть видео")
    fps = cap.get(cv2.CAP_PROP_FPS) or 25.0
    frames = cap.get(cv2.CAP_PROP_FRAME_COUNT) or 0
    W = cap.get(cv2.CAP_PROP_FRAME_WIDTH) or 1
    H = cap.get(cv2.CAP_PROP_FRAME_HEIGHT) or 1
    duration = frames / fps if fps > 0 else 0
    cascade = cv2.CascadeClassifier(cv2.data.haarcascades + "haarcascade_frontalface_default.xml")
    if cascade.empty():
        fail("Не найдены данные детектора лиц opencv")
    scale = 640.0 / W if W > 640 else 1.0
    tracks = []          # {box, start, end}
    t = 0.0
    seen = 0
    while duration == 0 or t <= duration:
        cap.set(cv2.CAP_PROP_POS_MSEC, t * 1000.0)
        ok, frame = cap.read()
        if not ok:
            break
        seen += 1
        small = cv2.resize(frame, None, fx=scale, fy=scale) if scale != 1.0 else frame
        gray = cv2.cvtColor(small, cv2.COLOR_BGR2GRAY)
        gray = cv2.equalizeHist(gray)
        faces = cascade.detectMultiScale(gray, scaleFactor=1.2, minNeighbors=6, minSize=(28, 28))
        for (x, y, w, h) in faces:
            box = (x / scale / W, y / scale / H, w / scale / W, h / scale / H)
            hit = None
            for tr in tracks:
                if tr["end"] >= t - step * 2.5 and iou(tr["box"], box) > 0.25:
                    hit = tr
                    break
            if hit:
                bx, by, bw, bh = hit["box"]
                x1, y1 = min(bx, box[0]), min(by, box[1])
                x2, y2 = max(bx + bw, box[0] + box[2]), max(by + bh, box[1] + box[3])
                hit["box"] = (x1, y1, x2 - x1, y2 - y1)
                hit["end"] = t
                hit["hits"] = hit.get("hits", 1) + 1
            else:
                tracks.append({"box": box, "start": t, "end": t, "hits": 1})
        t += step
        if seen > 900:
            break
    cap.release()
    out = []
    pad = 0.04
    for tr in sorted(tracks, key=lambda z: -(z["hits"])):
        x, y, w, h = tr["box"]
        x = max(0.0, x - pad); y = max(0.0, y - pad)
        w = min(1.0 - x, w + pad * 2); h = min(1.0 - y, h + pad * 2)
        start = max(0.0, tr["start"] - step)
        end = tr["end"] + step
        if duration:
            end = min(duration, end)
        out.append({"x": round(x, 4), "y": round(y, 4), "w": round(w, 4), "h": round(h, 4),
                    "start": round(start, 2), "end": round(end, 2), "hits": tr["hits"]})
        if len(out) >= max_regions:
            break
    out.sort(key=lambda z: z["start"])
    print(json.dumps({"ok": True, "regions": out, "duration": round(duration, 2), "frames": seen}, ensure_ascii=False))


main()
