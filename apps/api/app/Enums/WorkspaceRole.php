<?php

namespace App\Enums;

enum WorkspaceRole: string
{
    case Viewer = 'viewer';
    case Editor = 'editor';
    case Reviewer = 'reviewer';
    case Admin = 'admin';
    case Owner = 'owner';

    /**
     * Os papeis sao lineares e acumulativos: reviewer faz tudo que editor faz,
     * mais aprovar. A ordem aqui e o que faz "reviewer+" nao incluir editores.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Viewer => 0,
            self::Editor => 1,
            self::Reviewer => 2,
            self::Admin => 3,
            self::Owner => 4,
        };
    }

    public function atLeast(self $minimum): bool
    {
        return $this->rank() >= $minimum->rank();
    }
}
