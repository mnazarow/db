// Раздел «Возможности»: включение и отключение разделов и функций портала.
// Рядом с каждым пунктом показываем, сколько им пользовались — чтобы решение принималось по фактам,
// а не по памяти администратора.
import { one, query } from '../../db.js';
import { FEATURE_GROUPS, FEATURE_KEYS, featureByKey, DEP_TITLES } from '../../lib/features.js';
import { loadSettings, setSettings } from '../../lib/settings.js';
import { audit } from '../../lib/audit.js';
import { badRequest } from '../../lib/util.js';

/** Сколько раз этим пользовались. Ошибка в одном запросе не должна ронять страницу. */
async function usage() {
  const out = {};
  for (const g of FEATURE_GROUPS) {
    for (const item of g.items) {
      if (!item.stat) continue;
      try { out[item.key] = (await one(item.stat))?.count ?? null; } catch { out[item.key] = null; }
    }
  }
  return out;
}

export default async function adminFeatureRoutes(app) {
  app.addHook('preHandler', app.requireAdmin);

  app.get('/', async () => {
    const s = await loadSettings(true);
    const stats = await usage();
    const groups = FEATURE_GROUPS.map((g) => ({
      id: g.id, title: g.title, hint: g.hint,
      items: g.items.map((i) => ({
        key: i.key,
        title: i.title,
        desc: i.desc,
        needs: i.needs || [],
        deps: i.deps || [],
        enabled: s[i.key] !== false,
        // Пункт бесполезен, если выключено то, на чём он держится
        blockedBy: (i.deps || []).filter((d) => s[d] === false).map((d) => ({ key: d, title: featureByKey(d)?.title || DEP_TITLES[d] || d })),
        usage: stats[i.key] ?? null,
      })),
    }));
    return { groups, total: FEATURE_KEYS.length, off: FEATURE_KEYS.filter((k) => s[k] === false).length };
  });

  app.patch('/', async (req) => {
    const body = req.body?.features;
    if (!body || typeof body !== 'object') throw badRequest('Ожидается объект features');
    const entries = {};
    for (const [key, value] of Object.entries(body)) {
      if (!FEATURE_KEYS.includes(key)) continue;
      entries[key] = value === true || value === 'true';
    }
    if (!Object.keys(entries).length) throw badRequest('Не указано ни одной известной возможности');
    await setSettings(entries, req.user.id);
    const changed = Object.entries(entries).map(([k, v]) => `${featureByKey(k)?.title || k}: ${v ? 'включено' : 'выключено'}`);
    await audit(req, 'admin.features', { details: { changed } });
    const s = await loadSettings(true);
    return { ok: true, changed: changed.length, features: Object.fromEntries(FEATURE_KEYS.map((k) => [k, s[k] !== false])) };
  });

  // Выключить всё, чем ни разу не пользовались (кроме того, что уже выключено).
  // Ничего не удаляет: включить обратно можно в любой момент.
  app.post('/disable-unused', async (req) => {
    const s = await loadSettings(true);
    const stats = await usage();
    const entries = {};
    for (const g of FEATURE_GROUPS) {
      for (const i of g.items) {
        if (!i.stat) continue;                 // без счётчика судить не о чем
        if (s[i.key] === false) continue;
        if ((stats[i.key] ?? null) !== 0) continue;
        entries[i.key] = false;
      }
    }
    if (!Object.keys(entries).length) return { ok: true, disabled: 0, titles: [] };
    await setSettings(entries, req.user.id);
    const titles = Object.keys(entries).map((k) => featureByKey(k)?.title || k);
    await audit(req, 'admin.features', { details: { changed: titles.map((t) => `${t}: выключено (не использовалось)`) } });
    return { ok: true, disabled: titles.length, titles };
  });
}
