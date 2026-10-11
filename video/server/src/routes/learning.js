// Раздел «Обучение»: сводка по курсам и назначениям для руководителя.
import { forbidden } from '../lib/util.js';
import { canAssign } from '../lib/access.js';
import { learningOverview } from '../lib/learning.js';
import { toCsv, sendCsv } from '../lib/csv.js';

export default async function learningRoutes(app) {
  app.get('/learning/overview', { preHandler: app.requireActive }, async (req, reply) => {
    if (!canAssign(req.user, req.settings)) throw forbidden('Раздел доступен модераторам и администраторам');
    const groupId = req.query.groupId && /^[0-9a-f-]{36}$/i.test(String(req.query.groupId)) ? String(req.query.groupId) : null;
    const data = await learningOverview({ groupId });
    if (req.query.format === 'csv') {
      const csv = toCsv(data.people, [
        { title: 'Сотрудник', value: 'displayName' },
        { title: 'Почта', value: 'email' },
        { title: 'Группы', value: 'groups' },
        { title: 'Назначений выполнено', value: (p) => `${p.assignmentsDone} из ${p.assignmentsTotal}` },
        { title: 'Просрочено', value: 'assignmentsOverdue' },
        { title: 'Курсов пройдено', value: (p) => `${p.coursesDone} из ${p.coursesTotal}` },
        { title: 'Средний прогресс', value: (p) => (p.percent === null ? '' : `${p.percent}%`) },
      ]);
      return sendCsv(reply, 'learning-overview.csv', csv);
    }
    return data;
  });
}
