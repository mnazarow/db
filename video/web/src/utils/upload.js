// Возобновляемая загрузка видео по частям: инициализация, отправка чанков с повторами, завершение.
import { post, get, del, ApiError } from '../api.js';
import { url as withBase } from '../base.js';

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export class ChunkedUpload {
  constructor(file, meta = {}) {
    this.file = file;
    this.meta = meta;
    this.uploadId = null;
    this.videoId = null;
    this.shortId = null;
    this.chunkSize = 8 * 1024 * 1024;
    this.offset = 0;
    this.status = 'pending'; // pending | uploading | paused | done | error | cancelled
    this.progress = 0;
    this.error = null;
    this.speed = 0;
    this.eta = 0;
    this.video = null;
    this.onChange = () => {};
    this._abort = null;
    this._lastBytes = 0;
    this._lastTime = 0;
    this.detail = '';
    this._reinited = false;
  }

  emit() { this.onChange(this); }

  async init() {
    // Косая черта обязательна: без неё nginx с блоком «location /api/uploads/» отвечает 301,
    // а браузер на 301 превращает POST в GET и теряет тело запроса.
    const r = await post('/api/uploads/', { filename: this.file.name, size: this.file.size, mime: this.file.type, ...this.meta });
    // Без идентификатора продолжать нельзя: иначе следующий запрос уйдёт на /api/uploads/undefined
    // и человек увидит непонятное «Загрузка не найдена» вместо настоящей причины.
    if (!r || !UUID_RE.test(String(r.uploadId || ''))) {
      throw new ApiError(0, 'Портал не вернул идентификатор загрузки. Проверьте связь с сервером кнопкой «Проверить загрузку» и покажите результат администратору.', r);
    }
    this.uploadId = r.uploadId; this.videoId = r.videoId; this.shortId = r.shortId; this.chunkSize = r.chunkSize || this.chunkSize; this.video = r.video;
    this.offset = 0;
  }

  async start() {
    try {
      if (!this.uploadId) await this.init();
      else {
        // Возобновление: если начатой загрузки на сервере уже нет (истекла, отменена, сервер перезапущен),
        // начинаем заново, а не упираемся навсегда в «Загрузка не найдена».
        try {
          const r = await get(`/api/uploads/${this.uploadId}`);
          this.offset = r.offset;
        } catch (e) {
          if (e instanceof ApiError && (e.status === 404 || e.status === 410)) { this.uploadId = null; this.offset = 0; await this.init(); }
          else throw e;
        }
      }
      this.status = 'uploading'; this.error = null; this.emit();
      await this.loop();
    } catch (e) {
      if (this.status !== 'cancelled' && this.status !== 'paused') {
        this.status = 'error';
        this.error = e.message;
        // Код показываем только когда он сам по себе что-то говорит и его нет в тексте
        this.detail = e instanceof ApiError && e.status >= 400 ? `код ${e.status}` : '';
        this.emit();
      }
    }
  }

  async loop() {
    this._lastBytes = this.offset; this._lastTime = Date.now();
    while (this.offset < this.file.size && this.status === 'uploading') {
      const end = Math.min(this.file.size, this.offset + this.chunkSize);
      const blob = this.file.slice(this.offset, end);
      let attempt = 0;
      for (;;) {
        try {
          const r = await this.sendChunk(blob, this.offset);
          this.offset = r.offset;
          break;
        } catch (e) {
          if (this.status !== 'uploading') return;
          if (e instanceof ApiError && e.status === 409 && e.data?.offset !== undefined) { this.offset = e.data.offset; break; }
          // Начатой загрузки на сервере вдруг нет: пробуем завести её заново — один раз, чтобы не ходить по кругу.
          // Если и после этого 404, значит запрос уходит не туда, где загрузка была создана.
          if (e instanceof ApiError && e.status === 404 && this.offset === 0 && !this._reinited) {
            this._reinited = true;
            await this.init();
            continue;
          }
          if (e instanceof ApiError && e.status === 404 && this._reinited) {
            throw new ApiError(404, 'Загрузка пропадает сразу после создания. Так бывает, когда на один адрес отвечают несколько экземпляров портала. Нажмите «Проверить загрузку» и покажите результат администратору.', e.data);
          }
          if (e instanceof ApiError && e.status >= 400 && e.status < 500 && e.status !== 408 && e.status !== 429) throw e;
          attempt++;
          if (attempt > 6) throw e;
          await new Promise((res) => setTimeout(res, Math.min(15000, 1000 * 2 ** attempt)));
        }
      }
      this.progress = this.offset / this.file.size;
      const now = Date.now();
      if (now - this._lastTime > 800) {
        this.speed = ((this.offset - this._lastBytes) / ((now - this._lastTime) / 1000)) || 0;
        this.eta = this.speed > 0 ? (this.file.size - this.offset) / this.speed : 0;
        this._lastBytes = this.offset; this._lastTime = now;
      }
      this.emit();
    }
    if (this.offset >= this.file.size && this.status === 'uploading') {
      const r = await post(`/api/uploads/${this.uploadId}/complete`, {});
      this.video = r.video; this.status = 'done'; this.progress = 1; this.emit();
    }
  }

  sendChunk(blob, offset) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      this._abort = () => xhr.abort();
      xhr.open('PATCH', withBase(`/api/uploads/${this.uploadId}`));
      xhr.setRequestHeader('Content-Type', 'application/offset+octet-stream');
      xhr.setRequestHeader('Upload-Offset', String(offset));
      xhr.upload.onprogress = (e) => { if (e.lengthComputable) { this.progress = (offset + e.loaded) / this.file.size; this.emit(); } };
      xhr.onload = () => {
        let data = null, parsed = true;
        try { data = JSON.parse(xhr.responseText); } catch { parsed = false; }
        if (xhr.status >= 200 && xhr.status < 300) {
          // Успех обязан быть JSON со смещением: иначе часть приняли не мы, и докачка пойдёт вразнос
          if (!parsed || typeof data?.offset !== 'number') {
            reject(new ApiError(xhr.status, `Портал ответил на часть файла не по-своему (код ${xhr.status}). Похоже, ответ подменили прокси, VPN или антивирус.`, data));
            return;
          }
          resolve(data);
        } else reject(new ApiError(xhr.status, (parsed && data?.error) || `Ошибка ${xhr.status}`, data));
      };
      xhr.onerror = () => reject(new ApiError(0, 'Сетевая ошибка'));
      xhr.onabort = () => reject(new ApiError(0, 'Прервано'));
      xhr.send(blob);
    });
  }

  pause() { if (this.status === 'uploading') { this.status = 'paused'; this._abort?.(); this.emit(); } }
  resume() { if (this.status === 'paused' || this.status === 'error') this.start(); }
  async cancel() {
    this.status = 'cancelled'; this._abort?.(); this.emit();
    if (this.uploadId) { try { await del(`/api/uploads/${this.uploadId}`); } catch { /* ignore */ } }
  }
}
