<?php

namespace App\Ai\Knowledge;

use App\Ai\Surface;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Laravel\Pennant\Feature;

class KnowledgeBase
{
    /**
     * @var array<string, Collection<int, KnowledgeArticle>>
     */
    private array $cache = [];

    public function __construct(private readonly ?string $path = null) {}

    /**
     * @return Collection<int, KnowledgeArticle>
     */
    public function all(): Collection
    {
        $path = $this->resolvePath();

        return $this->cache[$path] ??= $this->load($path);
    }

    /**
     * @return Collection<int, KnowledgeArticle>
     */
    public function accessibleTo(User $user, ?Surface $surface = null): Collection
    {
        $this->alignPermissionContext($user);

        return $this->all()
            ->filter(fn (KnowledgeArticle $article): bool => $surface === null || $article->availableOnSurface($surface))
            ->filter(fn (KnowledgeArticle $article): bool => $this->userCanAccess($article, $user))
            ->values();
    }

    /**
     * @return Collection<int, KnowledgeArticle>
     */
    public function search(string $query, User $user, ?Surface $surface = null, int $limit = 3): Collection
    {
        return $this->accessibleTo($user, $surface)
            ->map(fn (KnowledgeArticle $article): array => [
                'article' => $article,
                'score' => $article->relevance($query),
            ])
            ->filter(fn (array $scored): bool => $scored['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->map(fn (array $scored): KnowledgeArticle => $scored['article'])
            ->values();
    }

    private function resolvePath(): string
    {
        return $this->path
            ?? config('otto.knowledge.path')
            ?? resource_path('otto-knowledge');
    }

    /**
     * @return Collection<int, KnowledgeArticle>
     */
    private function load(string $path): Collection
    {
        if (! File::isDirectory($path)) {
            return collect();
        }

        return collect(File::allFiles($path))
            ->filter(fn ($file): bool => strtolower($file->getExtension()) === 'md')
            ->reject(fn ($file): bool => strtolower($file->getFilenameWithoutExtension()) === 'readme')
            ->map(fn ($file): KnowledgeArticle => KnowledgeArticle::fromMarkdown(
                $file->getFilenameWithoutExtension(),
                File::get($file->getRealPath()),
            ))
            ->values();
    }

    private function alignPermissionContext(User $user): void
    {
        if ($user->org_id === null) {
            return;
        }

        try {
            setPermissionsTeamId($user->org_id);
        } catch (\Throwable) {
        }
    }

    private function userCanAccess(KnowledgeArticle $article, User $user): bool
    {
        if ($article->permissions === [] && $article->features === []) {
            return true;
        }

        foreach ($article->permissions as $permission) {
            try {
                if (! $user->can($permission)) {
                    return false;
                }
            } catch (\Throwable) {
                return false;
            }
        }

        foreach ($article->features as $feature) {
            if (! $this->featureActive($feature, $user)) {
                return false;
            }
        }

        return true;
    }

    private function featureActive(string $feature, User $user): bool
    {
        $class = 'App\\Features\\'.$feature;

        if (! class_exists($class)) {
            return false;
        }

        try {
            if (Feature::for($user)->active($class)) {
                return true;
            }

            $org = $user->org;

            return $org !== null && Feature::for($org)->active($class);
        } catch (\Throwable) {
            return false;
        }
    }
}
