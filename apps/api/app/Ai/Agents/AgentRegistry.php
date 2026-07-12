<?php

namespace App\Ai\Agents;

use InvalidArgumentException;

/**
 * `ai_runs.agent` guarda o nome; aqui ele vira instancia. Os outros seis agentes
 * entram como uma linha cada, sem tocar no RunAgentJob.
 */
class AgentRegistry
{
    /** @var array<string, class-string<Agent>> */
    private const AGENTS = [
        'strategist' => StrategistAgent::class,
        'copywriter' => CopywriterAgent::class,
        'social_media' => SocialMediaAgent::class,
        'reviewer' => ReviewerAgent::class,
        'designer' => DesignerAgent::class,
        'seo' => SeoAgent::class,
        'analytics' => AnalyticsAgent::class,
    ];

    public function get(string $name): Agent
    {
        $class = self::AGENTS[$name] ?? throw new InvalidArgumentException("Agente desconhecido: {$name}");

        return app($class);
    }
}
