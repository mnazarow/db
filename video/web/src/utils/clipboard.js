// Копирование в буфер обмена. navigator.clipboard доступен только в защищённом контексте (https или localhost)
// и требует «живого» жеста пользователя, поэтому сначала пробуем синхронный способ — он работает и по http,
// и во фрейме без разрешения clipboard-write.
function copySync(text) {
  try {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.cssText = 'position:fixed;top:0;left:-9999px;opacity:0';
    document.body.appendChild(ta);
    ta.select();
    ta.setSelectionRange(0, text.length);
    const ok = document.execCommand('copy');
    document.body.removeChild(ta);
    return !!ok;
  } catch { return false; }
}

export async function copyText(text) {
  const s = String(text ?? '');
  if (!s) return false;
  if (copySync(s)) return true;
  try {
    if (navigator.clipboard && window.isSecureContext) { await navigator.clipboard.writeText(s); return true; }
  } catch { /* обе попытки не удались */ }
  return false;
}

/** Копирование с уведомлением: copyWithToast(ui, text) */
export async function copyWithToast(ui, text, okMessage = 'Скопировано') {
  const ok = await copyText(text);
  ui?.toast?.(ok ? okMessage : 'Не удалось скопировать — скопируйте вручную', { type: ok ? 'success' : 'error' });
  return ok;
}
