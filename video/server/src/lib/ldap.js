// Вход через LDAP / Active Directory (библиотека ldapts).
import { Client } from 'ldapts';
import { getSettings } from './settings.js';

function escapeFilter(v) {
  return String(v).replace(/[\\*()\0]/g, (c) => ({ '\\': '\\5c', '*': '\\2a', '(': '\\28', ')': '\\29', '\0': '\\00' }[c]));
}

/**
 * Проверка учётных данных в LDAP.
 * Возвращает { dn, email, name, login, groups } или бросает ошибку.
 */
export async function ldapAuthenticate(login, password, overrides = null) {
  const s = overrides || await getSettings('ldap.');
  if (!s['ldap.enabled'] && !overrides) throw new Error('LDAP отключён');
  if (!password) throw new Error('Пустой пароль');
  const client = new Client({
    url: s['ldap.url'],
    timeout: Number(s['ldap.timeout_ms']) || 8000,
    connectTimeout: Number(s['ldap.timeout_ms']) || 8000,
    tlsOptions: { rejectUnauthorized: !!s['ldap.tls_reject_unauthorized'] },
  });
  try {
    // 1. Служебная привязка для поиска пользователя
    if (s['ldap.bind_dn']) await client.bind(s['ldap.bind_dn'], s['ldap.bind_password']);
    const filter = String(s['ldap.user_filter'] || '(sAMAccountName={login})').replace(/\{login\}/g, escapeFilter(login));
    const attrs = [s['ldap.attr_email'] || 'mail', s['ldap.attr_name'] || 'displayName', s['ldap.attr_login'] || 'sAMAccountName', 'memberOf', 'userPrincipalName', 'cn'];
    const { searchEntries } = await client.search(s['ldap.base_dn'], { scope: 'sub', filter, attributes: attrs, sizeLimit: 2 });
    if (!searchEntries.length) throw new Error('Пользователь не найден в каталоге');
    const entry = searchEntries[0];
    const dn = entry.dn;
    // 2. Привязка от имени пользователя — проверка пароля
    await client.bind(dn, password);
    const pickAttr = (name) => {
      const v = entry[name];
      if (Array.isArray(v)) return v.length ? String(v[0]) : '';
      return v == null ? '' : String(v);
    };
    let groups = [].concat(entry.memberOf || []).map(String);
    // Вложенные группы AD: все группы, в которые пользователь входит транзитивно (LDAP_MATCHING_RULE_IN_CHAIN)
    if (s['ldap.nested_groups']) {
      try {
        if (s['ldap.bind_dn']) await client.bind(s['ldap.bind_dn'], s['ldap.bind_password']);
        const res = await client.search(s['ldap.base_dn'], { scope: 'sub', filter: `(member:1.2.840.113556.1.4.1941:=${escapeFilter(dn)})`, attributes: ['dn'], sizeLimit: 500 });
        const nested = res.searchEntries.map((g) => String(g.dn));
        groups = [...new Set([...groups, ...nested])];
      } catch { /* каталог без поддержки правила — остаёмся с прямым членством */ }
    }
    const requiredGroup = s['ldap.required_group_dn'];
    if (requiredGroup && !groups.some((g) => g.toLowerCase() === requiredGroup.toLowerCase())) {
      throw new Error('Пользователь не входит в разрешённую группу');
    }
    const adminGroup = s['ldap.admin_group_dn'];
    return {
      dn,
      email: pickAttr(s['ldap.attr_email'] || 'mail').toLowerCase() || pickAttr('userPrincipalName').toLowerCase(),
      name: pickAttr(s['ldap.attr_name'] || 'displayName') || pickAttr('cn') || login,
      login: pickAttr(s['ldap.attr_login'] || 'sAMAccountName') || login,
      groups,
      isAdmin: !!(adminGroup && groups.some((g) => g.toLowerCase() === adminGroup.toLowerCase())),
    };
  } finally {
    try { await client.unbind(); } catch { /* ignore */ }
  }
}

/** Проверка подключения из админки (служебная привязка + поиск). */
export async function ldapTest(settings) {
  const client = new Client({
    url: settings['ldap.url'],
    timeout: Number(settings['ldap.timeout_ms']) || 8000,
    connectTimeout: Number(settings['ldap.timeout_ms']) || 8000,
    tlsOptions: { rejectUnauthorized: !!settings['ldap.tls_reject_unauthorized'] },
  });
  try {
    if (settings['ldap.bind_dn']) await client.bind(settings['ldap.bind_dn'], settings['ldap.bind_password']);
    const { searchEntries } = await client.search(settings['ldap.base_dn'], { scope: 'sub', filter: '(objectClass=*)', attributes: ['dn'], sizeLimit: 1 });
    return { ok: true, entries: searchEntries.length };
  } finally {
    try { await client.unbind(); } catch { /* ignore */ }
  }
}
