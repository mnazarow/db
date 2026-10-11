// Внутренние адреса для портала документации (раздел «Видео»): состояние видеопортала, число видео на
// модерации, обязательные просмотры по сотрудникам, поиск видео, запуск синхронизации сотрудников. Только с общим секретом PORTAL_SHARED_SECRET
// в заголовке X-Video-Secret (без него — 404, как будто адресов нет); снаружи nginx их не пропускает.
import { verifyPortalSecret, statusReport, moderationSummary, searchVideos, assignmentsByPortalUser, contextForQuestion } from '../lib/portal-internal.js';
import { syncPortalDirectory } from '../lib/portal-sync.js';

export default async function internalRoutes(app) {
  app.addHook('onRequest', async (req, reply) => {
    if (!verifyPortalSecret(req.headers['x-video-secret'])) {
      reply.code(404).send({ error: 'Не найдено' });
      return reply;
    }
    reply.header('Cache-Control', 'no-store');
  });

  app.get('/status', async () => statusReport());
  app.get('/moderation', async () => moderationSummary());
  app.get('/assignments', async () => assignmentsByPortalUser());
  app.get('/search', async (req) => searchVideos({ q: req.query.q, audience: req.query.audience, limit: req.query.limit }));
  app.get('/context', async (req) => contextForQuestion({ q: req.query.q, audience: req.query.audience, limit: req.query.limit }));
  app.post('/portal-sync', async () => ({ ok: true, stats: await syncPortalDirectory() }));
}
