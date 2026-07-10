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
