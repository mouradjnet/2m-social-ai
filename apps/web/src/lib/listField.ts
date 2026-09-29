/**
 * Ponte entre o campo `jsonb` (array de strings) e o <textarea> do wizard,
 * um item por linha. Sem DOM, sem React: so string <-> array.
 */

/** ['Carros','Motos'] → "Carros\nMotos" */
export function toLines(items: string[] | null | undefined): string {
  return (items ?? []).join('\n')
}

/** "Carros\n \nMotos\n" → ['Carros','Motos'] — corta espacos, descarta vazias. */
export function fromLines(text: string): string[] {
  return text
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line.length > 0)
}

/** O concorrente como a API guarda (`[{name, url}]`, ver docs/API.md). */
export type Competitor = { name: string; url: string | null }

/**
 * Uma linha por concorrente: "Nome" ou "Nome https://link". O link e opcional e
 * so conta se for a ULTIMA palavra e comecar com https:// (a API recusa http).
 * Linha so com o link usa o link como nome: a API exige `name`.
 */
export function competitorsFromLines(text: string): Competitor[] {
  return fromLines(text).map((line) => {
    const partes = line.split(/\s+/)
    const ultima = partes[partes.length - 1]

    if (!ultima.startsWith('https://')) return { name: line, url: null }

    // "Clínica X - https://…" e "Clínica X: https://…": o separador nao e do nome.
    const nome = partes
      .slice(0, -1)
      .join(' ')
      .replace(/\s*[-–—|:]$/, '')
    return { name: nome || ultima, url: ultima }
  })
}

/** O caminho de volta: {name:'Rival', url:'https://…'} → "Rival https://…". */
export function competitorsToLines(items: Competitor[] | null | undefined): string {
  return toLines(
    (items ?? []).map((c) => (c.url && c.url !== c.name ? `${c.name} ${c.url}` : c.name)),
  )
}
