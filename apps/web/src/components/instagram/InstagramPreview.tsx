import { useState } from 'react'
import { CAPTION_MAX, composeCaption } from '@/lib/instagram'
import { cn } from '@/lib/cn'
import type { Content } from '@/lib/types'

interface Props {
  content: Pick<Content, 'caption' | 'cta' | 'hashtags'>
  imageUrl: string | null
  username: string | null
  /** Reel: o video (a imagem vira o poster). `undefined` = nao e Reel. */
  videoUrl?: string | null
  /** "Carrossel · 3 imagens", "Reel". */
  badge?: string
}

/**
 * A previa do post como aparece no feed: imagem, @ e a legenda cortada em "mais". Nao
 * imita a marca do Instagram — mostra o que vai ao ar, e o que falta para ir.
 */
export function InstagramPreview({ content, imageUrl, username, videoUrl, badge }: Props) {
  const [inteira, setInteira] = useState(false)
  const legenda = composeCaption(content)
  const passou = legenda.length > CAPTION_MAX
  // O feed corta em ~125 caracteres ou 3 linhas — o que vier primeiro.
  const cortada = legenda.length > 125 || legenda.split('\n').length > 3

  return (
    <figure
      aria-label="Prévia do post"
      className="border-outline-variant bg-surface-container-lowest w-full max-w-sm self-start overflow-hidden rounded-card border"
    >
      <div className="flex items-center gap-2 px-3 py-2">
        <span className="bg-primary-container inline-block h-7 w-7 rounded-full" aria-hidden />
        <span className="text-label-md text-on-surface">{username ? `@${username}` : 'Conta não conectada'}</span>
      </div>

      {badge && <p className="text-label-sm text-on-surface-variant px-3 pb-2">{badge}</p>}

      {videoUrl !== undefined ? (
        videoUrl ? (
          <video
            src={videoUrl}
            poster={imageUrl ?? undefined}
            aria-label="Vídeo do Reel"
            className="aspect-[9/16] max-h-[28rem] w-full bg-black object-contain"
            controls
            muted
            preload="metadata"
          />
        ) : (
          <div className="bg-surface-container text-body-sm text-on-surface-variant flex aspect-[9/16] max-h-[28rem] w-full items-center justify-center p-6 text-center">
            Sem vídeo. O Reel não é publicado sem vídeo.
          </div>
        )
      ) : imageUrl ? (
        <img src={imageUrl} alt="Imagem do post" className="aspect-square w-full object-cover" />
      ) : (
        <div className="bg-surface-container text-body-sm text-on-surface-variant flex aspect-square w-full items-center justify-center p-6 text-center">
          Sem imagem. O Instagram não publica post sem imagem.
        </div>
      )}

      <figcaption className="px-3 py-3">
        <p className={cn('text-body-sm text-on-surface whitespace-pre-line', !inteira && 'line-clamp-3')}>
          {username && <strong className="font-display mr-1">{username}</strong>}
          {legenda || <span className="text-on-surface-variant">Sem legenda.</span>}
        </p>

        {!inteira && cortada && (
          <button type="button" className="text-body-sm text-on-surface-variant mt-1" onClick={() => setInteira(true)}>
            … mais
          </button>
        )}

        <p className={cn('text-label-sm mt-2', passou ? 'text-error' : 'text-on-surface-variant')}>
          {legenda.length}/{CAPTION_MAX} caracteres
        </p>
      </figcaption>
    </figure>
  )
}
