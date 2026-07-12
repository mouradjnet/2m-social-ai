/**
 * `navigator.clipboard` e undefined em contexto nao-seguro (HTTP puro, IP na rede
 * local). O dev roda em localhost, que e seguro; um deploy em HTTP nao seria — e o
 * botao morreria em silencio. Dai o fallback com textarea oculta.
 */
export async function copyToClipboard(text: string): Promise<boolean> {
  if (navigator.clipboard?.writeText) {
    try {
      await navigator.clipboard.writeText(text)

      return true
    } catch {
      // Permissao negada ou documento sem foco: cai no fallback.
    }
  }

  const textarea = document.createElement('textarea')
  textarea.value = text
  textarea.setAttribute('readonly', '')
  textarea.style.position = 'fixed'
  textarea.style.opacity = '0'
  document.body.appendChild(textarea)
  textarea.select()

  const copiado = document.execCommand('copy')
  document.body.removeChild(textarea)

  return copiado
}
