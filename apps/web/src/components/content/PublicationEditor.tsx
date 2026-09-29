import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { InstagramPreview } from '@/components/instagram/InstagramPreview'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Input } from '@/components/ui/Input'
import { Textarea } from '@/components/ui/Textarea'
import { api, errorMessage } from '@/lib/api'
import { cn } from '@/lib/cn'
import { HASHTAGS_MAX, formatDateTime, parseHashtags } from '@/lib/instagram'
import type { Asset, Content, InstagramAccount } from '@/lib/types'

interface Props {
  projectId: string
  content: Content
  onClose: () => void
  /** Gera a imagem por IA a partir do `image_prompt` (ADR-15). A execucao e da pagina. */
  onGenerateImage?: () => void
  generatingImage?: boolean
}

/** Depois de aprovada, a peca tem o texto e a imagem congelados (ADR-13). */
const EDITAVEIS: Content['status'][] = ['idea', 'production', 'review']

/**
 * O editor de publicacao: texto, imagem e a previa do post, lado a lado. Aprovada, a
 * peca vira somente leitura — o que muda e so a data, e agendar e feito aqui.
 */
export function PublicationEditor({ projectId, content, onClose, onGenerateImage, generatingImage = false }: Props) {
  const queryClient = useQueryClient()
  const editavel = EDITAVEIS.includes(content.status)

  const [title, setTitle] = useState(content.title)
  const [caption, setCaption] = useState(content.caption ?? '')
  const [cta, setCta] = useState(content.cta ?? '')
  const [hashtags, setHashtags] = useState(content.hashtags.join(' '))
  const [imageId, setImageId] = useState<number | null>(content.image?.id ?? content.image_asset_id ?? null)
  const imagemDoServidor = content.image?.id ?? content.image_asset_id ?? null
  const [when, setWhen] = useState('')

  const assets = useQuery({
    queryKey: ['assets', projectId],
    queryFn: () => api<{ data: Asset[] }>(`/projects/${projectId}/assets`),
  })

  const conta = useQuery({
    queryKey: ['instagram', projectId],
    queryFn: () => api<{ data: InstagramAccount | null }>(`/projects/${projectId}/instagram`),
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['contents', projectId] })

  // A imagem mudou no servidor (a IA desenhou uma): a selecao acompanha, e a
  // biblioteca e relida. Sem isto, "Salvar" mandaria de volta a imagem antiga.
  useEffect(() => {
    setImageId(imagemDoServidor)
    void queryClient.invalidateQueries({ queryKey: ['assets', projectId] })
  }, [imagemDoServidor, projectId, queryClient])

  const salvar = useMutation({
    mutationFn: async () => {
      await api(`/contents/${content.id}/draft`, {
        method: 'PATCH',
        body: JSON.stringify({ title, caption, cta, hashtags: parseHashtags(hashtags) }),
      })

      if (imageId !== imagemDoServidor) {
        await api(`/contents/${content.id}/image`, {
          method: 'PUT',
          body: JSON.stringify({ asset_id: imageId }),
        })
      }
    },
    onSuccess: async () => {
      await invalidate()
      onClose()
    },
  })

  const agendar = useMutation({
    mutationFn: (quando: string) =>
      api(`/contents/${content.id}/schedule`, {
        method: 'POST',
        body: JSON.stringify({ scheduled_for: quando }),
      }),
    onSuccess: async () => {
      await invalidate()
      onClose()
    },
  })

  const lista = assets.data?.data ?? []
  const imagem = lista.find((a) => a.id === imageId) ?? content.image ?? null
  const tags = parseHashtags(hashtags)

  return (
    <Card className="mt-6" aria-label="Editor de publicação">
      <div className="flex items-center justify-between gap-4">
        <h2 className="text-headline-md font-display text-on-surface">
          {editavel ? 'Editar publicação' : 'Publicação'}
        </h2>
        <Button variant="ghost" size="sm" onClick={onClose}>
          Fechar
        </Button>
      </div>

      {content.approver && content.approved_at && (
        <p className="text-body-sm text-secondary mt-2">
          ✓ Aprovada por {content.approver.name} em {formatDateTime(content.approved_at)}
        </p>
      )}

      {!editavel && (
        <p className="text-body-sm text-on-surface-variant mt-2">
          Peça aprovada: texto e imagem ficam como foram aprovados. Para mudar, devolva para revisão.
        </p>
      )}

      <div className="mt-6 grid gap-8 md:grid-cols-[1fr_24rem]">
        <div className="flex flex-col gap-4">
          <Input label="Título (interno)" value={title} disabled={!editavel} onChange={(e) => setTitle(e.target.value)} />

          <Textarea
            label="Legenda"
            rows={6}
            value={caption}
            disabled={!editavel}
            onChange={(e) => setCaption(e.target.value)}
          />

          <Input label="Chamada para ação" value={cta} disabled={!editavel} onChange={(e) => setCta(e.target.value)} />

          <Input
            label={`Hashtags (${tags.length}/${HASHTAGS_MAX})`}
            value={hashtags}
            disabled={!editavel}
            onChange={(e) => setHashtags(e.target.value)}
          />

          <fieldset disabled={!editavel}>
            <legend className="text-label-md text-on-surface">Imagem</legend>

            {onGenerateImage && (
              <div className="mt-2 flex flex-wrap items-center gap-3">
                <Button
                  size="sm"
                  variant="secondary"
                  disabled={!content.image_prompt || generatingImage}
                  onClick={onGenerateImage}
                >
                  {generatingImage ? 'Gerando imagem…' : 'Gerar imagem com IA'}
                </Button>
                <span className="text-body-sm text-on-surface-variant">
                  {content.image_prompt
                    ? 'Usa o prompt do diretor de arte. A imagem entra na biblioteca e fica selecionada.'
                    : 'Sem prompt de imagem: rode "Gerar prompts de imagem" com a peça em Produção.'}
                </span>
              </div>
            )}

            {lista.length === 0 ? (
              <p className="text-body-sm text-on-surface-variant mt-2">
                A biblioteca está vazia.{' '}
                <Link to={`/projects/${projectId}/library`} className="text-primary hover:underline">
                  Subir imagens
                </Link>
              </p>
            ) : (
              <div className="mt-2 grid grid-cols-4 gap-2">
                <button
                  type="button"
                  aria-pressed={imageId === null}
                  onClick={() => setImageId(null)}
                  className={cn(
                    'border-outline-variant text-label-sm text-on-surface-variant aspect-square rounded border',
                    imageId === null && 'ring-primary ring-2',
                  )}
                >
                  Sem imagem
                </button>

                {lista.map((asset) => (
                  <button
                    key={asset.id}
                    type="button"
                    aria-pressed={imageId === asset.id}
                    aria-label={asset.original_name ?? `Imagem ${asset.id}`}
                    onClick={() => setImageId(asset.id)}
                    className={cn('overflow-hidden rounded', imageId === asset.id && 'ring-primary ring-2')}
                  >
                    <img src={asset.url} alt="" className="aspect-square w-full object-cover" />
                  </button>
                ))}
              </div>
            )}
          </fieldset>

          {editavel && (
            <div>
              <Button disabled={salvar.isPending} onClick={() => salvar.mutate()}>
                {salvar.isPending ? 'Salvando…' : 'Salvar'}
              </Button>

              {salvar.isError && (
                <p role="alert" className="text-body-sm text-error mt-2">
                  {errorMessage(salvar.error)}
                </p>
              )}
            </div>
          )}

          {content.status === 'approved' && (
            <div className="border-outline-variant flex flex-wrap items-end gap-3 border-t pt-4">
              <Input
                label="Publicar em (fuso do projeto)"
                type="datetime-local"
                value={when}
                onChange={(e) => setWhen(e.target.value)}
              />
              <Button disabled={when === '' || agendar.isPending} onClick={() => agendar.mutate(when)}>
                Agendar
              </Button>

              {/* A hora vai em UTC com o `Z`: o servidor respeita o offset. */}
              {content.planned_for && (
                <Button
                  variant="secondary"
                  disabled={agendar.isPending}
                  onClick={() => agendar.mutate(content.planned_for!)}
                >
                  Usar horário do plano ({formatDateTime(content.planned_for)})
                </Button>
              )}

              {agendar.isError && (
                <p role="alert" className="text-body-sm text-error w-full">
                  {errorMessage(agendar.error)}
                </p>
              )}
            </div>
          )}
        </div>

        <InstagramPreview
          content={{ caption, cta, hashtags: tags }}
          imageUrl={imagem?.url ?? null}
          username={conta.data?.data?.username ?? null}
        />
      </div>
    </Card>
  )
}
