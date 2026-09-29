import { cn } from '@/lib/cn'
import type { Asset } from '@/lib/types'

/** Limites da Meta para o carrossel (o servidor confere de novo). */
export const SLIDES_MIN = 2
export const SLIDES_MAX = 10

interface SlidesProps {
  images: Asset[]
  value: number[]
  onChange: (ids: number[]) => void
  disabled: boolean
}

/**
 * As imagens do carrossel, em ordem. Clicar numa imagem da biblioteca a poe no fim;
 * as setas mudam a ordem. A primeira abre o post e define o recorte das outras.
 */
export function SlidesPicker({ images, value, onChange, disabled }: SlidesProps) {
  const porId = new Map(images.map((a) => [a.id, a]))
  const mover = (i: number, d: -1 | 1) => {
    const novo = [...value]
    ;[novo[i], novo[i + d]] = [novo[i + d], novo[i]]
    onChange(novo)
  }

  return (
    <fieldset disabled={disabled}>
      <legend className="text-label-md text-on-surface">
        Slides do carrossel ({value.length}/{SLIDES_MAX})
      </legend>
      <p className={cn('text-body-sm mt-1', value.length < SLIDES_MIN ? 'text-error' : 'text-on-surface-variant')}>
        {value.length < SLIDES_MIN
          ? `O Instagram exige pelo menos ${SLIDES_MIN} imagens.`
          : 'A primeira imagem abre o post e define o recorte das outras.'}
      </p>

      {value.length > 0 && (
        <ol className="mt-2 flex flex-col gap-2" aria-label="Ordem dos slides">
          {value.map((id, i) => (
            <li key={id} className="border-outline-variant flex items-center gap-3 rounded border p-2">
              <span className="text-label-md text-on-surface-variant w-5">{i + 1}</span>
              <img src={porId.get(id)?.url} alt="" className="h-12 w-12 rounded object-cover" />
              <span className="text-body-sm text-on-surface flex-1 truncate">{porId.get(id)?.original_name ?? `Imagem ${id}`}</span>
              <button type="button" aria-label={`Subir slide ${i + 1}`} disabled={i === 0} onClick={() => mover(i, -1)}>
                ↑
              </button>
              <button type="button" aria-label={`Descer slide ${i + 1}`} disabled={i === value.length - 1} onClick={() => mover(i, 1)}>
                ↓
              </button>
              <button type="button" aria-label={`Tirar slide ${i + 1}`} onClick={() => onChange(value.filter((v) => v !== id))}>
                ✕
              </button>
            </li>
          ))}
        </ol>
      )}

      <div className="mt-2 grid grid-cols-4 gap-2">
        {images
          .filter((a) => !value.includes(a.id))
          .map((a) => (
            <button
              key={a.id}
              type="button"
              aria-label={`Adicionar ${a.original_name ?? `imagem ${a.id}`}`}
              disabled={value.length >= SLIDES_MAX}
              onClick={() => onChange([...value, a.id])}
              className="overflow-hidden rounded disabled:opacity-40"
            >
              <img src={a.url} alt="" className="aspect-square w-full object-cover" />
            </button>
          ))}
      </div>
    </fieldset>
  )
}

interface VideoProps {
  videos: Asset[]
  value: number | null
  onChange: (id: number | null) => void
  disabled: boolean
}

/** O video do Reel, entre os da biblioteca. */
export function VideoPicker({ videos, value, onChange, disabled }: VideoProps) {
  const segundos = (ms?: number | null) => (ms ? ` · ${Math.round(ms / 1000)} s` : '')

  return (
    <fieldset disabled={disabled}>
      <legend className="text-label-md text-on-surface">Vídeo do Reel</legend>
      {videos.length === 0 ? (
        <p className="text-body-sm text-on-surface-variant mt-2">Nenhum vídeo na biblioteca. Suba um MP4 na Biblioteca.</p>
      ) : (
        <div className="mt-2 flex flex-col gap-2">
          {videos.map((v) => (
            <button
              key={v.id}
              type="button"
              aria-pressed={value === v.id}
              onClick={() => onChange(value === v.id ? null : v.id)}
              className={cn(
                'border-outline-variant text-body-sm text-on-surface rounded border p-2 text-left',
                value === v.id && 'ring-primary ring-2',
              )}
            >
              🎬 {v.original_name ?? `Vídeo ${v.id}`}
              {segundos(v.duration_ms)}
            </button>
          ))}
        </div>
      )}
    </fieldset>
  )
}
