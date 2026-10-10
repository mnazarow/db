// Журнал действий (аудит).
import { query } from '../db.js';

export async function audit(req, action, { targetType = null, targetId = null, details = {} } = {}) {
  try {
    await query(
      'INSERT INTO audit_log(actor_id, action, target_type, target_id, details, ip) VALUES ($1,$2,$3,$4,$5::jsonb,$6)',
      [req?.user?.id || null, action, targetType, targetId ? String(targetId) : null, JSON.stringify(details || {}), req?.ip || null],
    );
  } catch (e) {
    console.error('audit failed:', e.message);
  }
}
