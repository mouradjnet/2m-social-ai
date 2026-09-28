/**
 * Para onde o navegador vai quando sai do app (o consentimento da Meta). Isolado num
 * objeto para o teste trocar: o jsdom nao navega.
 */
export const navegar = { para: (url: string) => window.location.assign(url) }
