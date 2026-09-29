import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Shell } from '@/components/ui/Shell'
import { ApiError, api, errorMessage, upload } from '@/lib/api'
import type { Asset } from '@/lib/types'

/** 42500 -> "0:42" */
function duracao(ms: number): string {
  const s = Math.round(ms / 1000)

  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`
}

function kb(bytes: number): string {
  return bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.round(bytes / 1024)} KB`
}

/**
 * A biblioteca de imagens do projeto. O servidor devolve cada imagem ja pronta para o
 * Instagram (JPEG, proporcao aceita, sem EXIF) — ou recusa com o motivo, aqui, no
 * upload, e nao de madrugada na hora de publicar.
 */
export function LibraryPage() {
  const { projectId } = useParams()
  const queryClient = useQueryClient()
  const input = useRef<HTMLInputElement>(null)
  // Remover pede confirmacao em dois cliques, sem window.confirm: um dialogo nativo
  // trava a aba e nao se testa.
  const [confirmando, setConfirmando] = useState<number | null>(null)

  const assets = useQuery({
    queryKey: ['assets', projectId],
    queryFn: () => api<{ data: Asset[] }>(`/projects/${projectId}/assets`),
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['assets', projectId] })

  const subir = useMutation({
    mutationFn: (file: File) => upload<{ data: Asset }>(`/projects/${projectId}/assets`, file),
    onSuccess: invalidate,
  })

  const remover = useMutation({
    mutationFn: (id: number) => api(`/assets/${id}`, { method: 'DELETE' }),
    onSuccess: () => {
      setConfirmando(null)
      return invalidate()
    },
  })

  if (assets.isPending) return <Shell>Carregando…</Shell>
  if (assets.isError) return <Shell>Projeto não encontrado.</Shell>

  const lista = assets.data.data
  const erroDoUpload =
    subir.error instanceof ApiError ? (subir.error.fieldError('file') ?? errorMessage(subir.error)) : null

  return (
    <Shell>
      <Link to={`/projects/${projectId}/content`} className="text-body-sm text-on-surface-variant hover:text-primary">
        ← Conteúdo
      </Link>

      <div className="mt-4 flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-display-lg text-on-surface">Biblioteca</h1>
          <p className="text-body-sm text-on-surface-variant mt-2">
            JPEG, PNG ou WebP até 8 MB. Proporção entre 4:5 (retrato) e 1.91:1 (paisagem).
          </p>
          <p className="text-body-sm text-on-surface-variant mt-1">
            Vídeo de Reel: MP4 ou MOV em H.264, de 3 s a 15 min, até 300 MB, exportado com “otimizar para web”.
          </p>
        </div>

        <input
          ref={input}
          type="file"
          accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime"
          className="hidden"
          aria-label="Arquivo de imagem"
          onChange={(e) => {
            const file = e.target.files?.[0]
            if (file) subir.mutate(file)
            e.target.value = ''
          }}
        />
        <Button disabled={subir.isPending} onClick={() => input.current?.click()}>
          {subir.isPending ? 'Enviando…' : 'Subir imagem ou vídeo'}
        </Button>
      </div>

      {erroDoUpload && (
        <p role="alert" className="text-body-sm text-error mt-4">
          {erroDoUpload}
        </p>
      )}

      {remover.isError && (
        <p role="alert" className="text-body-sm text-error mt-4">
          {errorMessage(remover.error)}
        </p>
      )}

      {lista.length === 0 ? (
        <p className="text-body-lg text-on-surface-variant mt-8">Nenhuma imagem ainda.</p>
      ) : (
        <ul className="mt-8 grid grid-cols-2 gap-4 md:grid-cols-4">
          {lista.map((asset) => (
            <li key={asset.id}>
              <Card className="p-3">
                {asset.type === 'video' ? (
                  <video
                    src={asset.url}
                    aria-label={asset.original_name ?? `Vídeo ${asset.id}`}
                    className="aspect-square w-full rounded bg-black object-cover"
                    preload="metadata"
                    muted
                    controls
                  />
                ) : (
                  <img
                    src={asset.url}
                    alt={asset.original_name ?? `Imagem ${asset.id}`}
                    className="aspect-square w-full rounded object-cover"
                  />
                )}
                <p className="text-label-sm text-on-surface mt-2 truncate" title={asset.original_name ?? undefined}>
                  {asset.original_name ?? `Imagem ${asset.id}`}
                </p>
                <p className="text-label-sm text-on-surface-variant mt-1">
                  {asset.type === 'video' && asset.duration_ms ? `🎬 ${duracao(asset.duration_ms)} · ` : ''}
                  {asset.width}×{asset.height} · {kb(asset.size_bytes)}
                  {asset.contents_count ? ` · em ${asset.contents_count} ${asset.contents_count === 1 ? 'peça' : 'peças'}` : ''}
                </p>

                {confirmando === asset.id ? (
                  <div className="mt-2 flex gap-2">
                    <Button size="sm" variant="secondary" disabled={remover.isPending} onClick={() => remover.mutate(asset.id)}>
                      Confirmar remoção
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => setConfirmando(null)}>
                      Cancelar
                    </Button>
                  </div>
                ) : (
                  <Button size="sm" variant="ghost" className="mt-2" onClick={() => setConfirmando(asset.id)}>
                    Remover
                  </Button>
                )}
              </Card>
            </li>
          ))}
        </ul>
      )}
    </Shell>
  )
}
