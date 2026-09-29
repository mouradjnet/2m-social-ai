import type { ContentFormat, ContentStructure } from '@/lib/types'

interface Props {
  format: ContentFormat
  structure: ContentStructure
}

/**
 * CP-03: o roteiro da peça por formato. É texto para produzir — o aviso no topo existe
 * para ninguém confundir um carrossel descrito com a arte pronta, ou um roteiro de
 * Reels com o vídeo.
 */
export function StructurePreview({ format, structure }: Props) {
  return (
    <details className="border-outline-variant mt-3 rounded border p-2">
      <summary className="text-label-sm text-on-surface cursor-pointer">
        Roteiro {ROTULO[format] ? `do ${ROTULO[format]}` : ''}
      </summary>

      <p className="text-label-sm text-on-surface-variant mt-2">
        Roteiro para produção — não é a arte nem o vídeo final.
      </p>

      <p className="text-body-sm text-on-surface mt-2">
        <span className="text-on-surface-variant">Proposta visual:</span> {structure.visual}
      </p>

      {format === 'carousel' && structure.slides && (
        <ol className="mt-2 flex list-decimal flex-col gap-1 pl-5">
          {structure.slides.map((slide, i) => (
            <li key={i} className="text-body-sm text-on-surface">
              <span className="font-medium">{slide.heading}</span>
              {slide.body && <span className="text-on-surface-variant"> — {slide.body}</span>}
            </li>
          ))}
        </ol>
      )}

      {format === 'story' && structure.screens && (
        <ol className="mt-2 flex list-decimal flex-col gap-1 pl-5">
          {structure.screens.map((tela, i) => (
            <li key={i} className="text-body-sm text-on-surface">
              {tela.text}
              {tela.interaction && (
                <span className="text-on-surface-variant"> · interação: {tela.interaction}</span>
              )}
            </li>
          ))}
        </ol>
      )}

      {format === 'reel' && (
        <div className="mt-2 flex flex-col gap-1">
          {structure.hook && (
            <p className="text-body-sm text-on-surface">
              <span className="text-on-surface-variant">Gancho:</span> {structure.hook}
            </p>
          )}
          {structure.scenes && (
            <ol className="flex list-decimal flex-col gap-1 pl-5">
              {structure.scenes.map((cena, i) => (
                <li key={i} className="text-body-sm text-on-surface">
                  {cena.description}
                  {cena.on_screen_text && (
                    <span className="text-on-surface-variant"> · na tela: “{cena.on_screen_text}”</span>
                  )}
                  {cena.narration && (
                    <span className="text-on-surface-variant"> · narração: {cena.narration}</span>
                  )}
                </li>
              ))}
            </ol>
          )}
          {structure.production_notes && (
            <p className="text-body-sm text-on-surface-variant">
              Produção: {structure.production_notes}
            </p>
          )}
        </div>
      )}
    </details>
  )
}

const ROTULO: Partial<Record<ContentFormat, string>> = {
  post: 'post',
  carousel: 'carrossel',
  reel: 'Reels',
  story: 'Stories',
}
