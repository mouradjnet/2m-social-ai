import { PUBLICATION_LABELS, publicationTone } from '@/lib/instagram'
import { cn } from '@/lib/cn'
import type { Publication } from '@/lib/types'

/** O estado da publicacao no Instagram, num chip. Publicado vira link para o post. */
export function PublicationBadge({ publication }: { publication: Publication }) {
  const chip = (
    <span
      className={cn(
        'text-label-sm inline-block rounded-full px-2 py-0.5',
        publicationTone(publication.status),
      )}
      title={publication.last_error ?? undefined}
    >
      {publication.status === 'published' ? '✓ ' : publication.status === 'failed' ? '⚠ ' : ''}
      Instagram: {PUBLICATION_LABELS[publication.status]}
    </span>
  )

  if (publication.status === 'published' && publication.permalink) {
    return (
      <a href={publication.permalink} target="_blank" rel="noreferrer" className="hover:underline">
        {chip}
      </a>
    )
  }

  return chip
}
