<?php

namespace App\Modules\Content\Experience\Controllers;

use App\Modules\Content\Application\ArticleGuidelineService;
use App\Modules\Content\Application\ContentAuthoringMode;
use App\Modules\Content\Application\ContentProductionService;
use App\Modules\Content\Application\ContentWorkflowStatus;
use App\Modules\Content\Application\Contracts\ContentSourceAdapter;
use App\Modules\Content\Application\DocumentImportService;
use App\Modules\Content\Application\EditorialService;
use App\Modules\Content\Application\Exceptions\ContentSourceRejected;
use App\Modules\Content\Application\Exceptions\DocumentImportException;
use App\Modules\Content\Application\Exceptions\EditorialException;
use App\Modules\Content\Application\Exceptions\PublicationException;
use App\Modules\Content\Application\PublicationService;
use App\Modules\Content\Application\WriterPoolService;
use App\Modules\Content\Infrastructure\ContentWriterScopeService;
use App\Modules\Content\Infrastructure\Models\ContentArticle;
use App\Modules\Content\Infrastructure\Yby\YbyContentOpportunityQuery;
use App\Modules\Identity\Application\RolePreset;
use App\Modules\Identity\Infrastructure\Models\User;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final readonly class ContentWorkspaceController
{
    public function __construct(
        private WriterPoolService $writerPool,
        private ContentProductionService $production,
        private ContentSourceAdapter $source,
        private ArticleGuidelineService $guidelines,
        private DocumentImportService $documents,
        private EditorialService $editorial,
        private PublicationService $publication,
        private YbyContentOpportunityQuery $opportunities,
        private ContentWriterScopeService $writerScopes,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $canManage = $user->can('content.manage');

        return Inertia::render('Content/Index', [
            'pool' => $this->writerPool->pool((int) $user->id, $canManage),
            'mine' => $this->writerPool->mine((int) $user->id),
            'canWrite' => $user->can('content.write'),
            'canManage' => $canManage,
            'opportunities' => $canManage ? $this->opportunities->list() : [],
            'writers' => $canManage ? $this->writers() : [],
            'projects' => $canManage ? $this->projects() : [],
        ]);
    }

    public function show(Request $request, int $article): Response
    {
        $record = ContentArticle::query()->findOrFail($article);
        $user = $request->user();
        $canManage = $user->can('content.manage');
        $canReview = $user->can('content.review');
        $canPublish = $user->can('content.publish');

        abort_unless($canManage || $canReview || $canPublish || (int) $record->writer_id === (int) $user->id, 403);

        $documentSource = DB::table('content_document_sources')->where('article_id', $record->id)->first();
        $editorialSnapshot = DB::table('content_snapshots')
            ->where('article_id', $record->id)
            ->where('type', 'editorial')
            ->orderByDesc('version')
            ->first();
        $editorialPayload = $editorialSnapshot
            ? json_decode((string) $editorialSnapshot->normalized_json, true)
            : null;
        $publication = DB::table('content_publications')
            ->where('article_id', $record->id)
            ->orderByDesc('id')
            ->first();
        $publicationPreview = $publication
            ? json_decode((string) $publication->preview_json, true)
            : null;

        return Inertia::render('Content/Show', [
            'article' => [
                'id' => (int) $record->id,
                'project_key' => $record->project_key,
                'target_site' => $record->target_site,
                'language' => $record->language,
                'content_type' => $record->content_type,
                'title_direction' => $record->title_direction,
                'primary_keyword' => $record->primary_keyword,
                'supporting_keywords' => $record->supporting_keywords ?? [],
                'search_intent' => $record->search_intent,
                'topic_cluster' => $record->topic_cluster,
                'authoring_mode' => $record->authoring_mode?->value,
                'workflow_status' => $record->workflow_status->value,
                'writer_id' => $record->writer_id ? (int) $record->writer_id : null,
                'claimed_at' => $record->claimed_at?->toIso8601String(),
                'claim_expires_at' => $record->claim_expires_at?->toIso8601String(),
            ],
            'guideline' => $record->guideline_json,
            'documentSource' => $documentSource ? [
                'document_url' => (string) $documentSource->document_url,
                'modified_time' => $documentSource->modified_time,
                'revision_id' => $documentSource->revision_id,
                'submitted_at' => $documentSource->submitted_at,
                'imported_at' => $documentSource->imported_at,
                'changed_after_submission' => (bool) $documentSource->changed_after_submission,
            ] : null,
            'latestWriterVersion' => (int) (DB::table('content_snapshots')
                ->where('article_id', $record->id)
                ->where('type', 'writer_original')
                ->max('version') ?? 0),
            'latestEditorialVersion' => $editorialSnapshot ? (int) $editorialSnapshot->version : 0,
            'editorialSnapshot' => is_array($editorialPayload) ? [
                'source' => $editorialPayload['source'] ?? null,
                'provider' => $editorialPayload['provider'] ?? null,
                'change_summary' => $editorialPayload['change_summary'] ?? [],
                'verification_required' => $editorialPayload['verification_required'] ?? [],
            ] : null,
            'aiEditorialAvailable' => $this->editorial->available(),
            'publication' => $publication ? [
                'status' => (string) $publication->status,
                'layout_snapshot_id' => (int) $publication->layout_snapshot_id,
                'preview_hash' => (string) $publication->preview_hash,
                'verification_resolved_at' => $publication->verification_resolved_at,
                'provider' => $publication->provider,
                'external_id' => $publication->external_id,
                'provider_status' => $publication->provider_status ?? null,
                'draft_url' => $publication->draft_url ?? null,
                'draft_synced_at' => $publication->draft_synced_at ?? null,
                'writer_approved_at' => $publication->writer_approved_at ?? null,
                'writer_approved_by' => isset($publication->writer_approved_by) ? (int) $publication->writer_approved_by : null,
                'fact_approved_at' => $publication->fact_approved_at ?? null,
                'fact_approved_by' => isset($publication->fact_approved_by) ? (int) $publication->fact_approved_by : null,
                'seo_approved_at' => $publication->seo_approved_at ?? null,
                'seo_approved_by' => isset($publication->seo_approved_by) ? (int) $publication->seo_approved_by : null,
                'requested_indexing' => $publication->requested_indexing ?? 'noindex',
                'published_url' => $publication->published_url,
                'published_at' => $publication->published_at,
                'preview' => is_array($publicationPreview) ? [
                    'title' => $publicationPreview['title'] ?? null,
                    'canonical_path' => $publicationPreview['canonical_path'] ?? null,
                    'html' => $publicationPreview['html'] ?? '',
                    'verification_required' => $publicationPreview['verification_required'] ?? [],
                    'source' => $publicationPreview['source'] ?? null,
                ] : null,
            ] : null,
            'canReview' => $canReview,
            'canPublish' => $canPublish,
            'canManage' => $canManage,
            'isOwner' => (int) $record->writer_id === (int) $user->id,
        ]);
    }

    public function createFromSource(Request $request, int $backlog): RedirectResponse
    {
        $validated = $request->validate([
            'authoring_mode' => ['required', Rule::in(['human', 'ai'])],
        ]);

        try {
            $articleId = $this->production->createArticleBase($this->source, (string) $backlog);
            $article = ContentArticle::query()->findOrFail($articleId);
            $requestedMode = ContentAuthoringMode::from($validated['authoring_mode']);

            if ($article->workflow_status === ContentWorkflowStatus::ArticleBaseReady) {
                $this->production->chooseAuthoringMode($articleId, $requestedMode);
            } elseif ($article->authoring_mode !== $requestedMode) {
                return back()->withErrors(['authoring_mode' => '该 Article Base 已选择另一种创作模式。']);
            }

            $this->guidelines->generate($articleId);

            return redirect()->route('content.show', $articleId)->with('success', 'Article Base 已进入内容生产流程。');
        } catch (ContentSourceRejected $exception) {
            return back()->withErrors(['source' => $exception->reason]);
        } catch (DomainException $exception) {
            return back()->withErrors(['content' => $exception->getMessage()]);
        }
    }

    public function claimNext(Request $request): RedirectResponse
    {
        $articleId = $this->writerPool->claimNext(
            (int) $request->user()->id,
            $request->user()->can('content.manage'),
        );

        if (! $articleId) {
            return back()->with('success', '当前没有符合范围的待领取文章。');
        }

        return redirect()->route('content.show', $articleId)->with('success', '已领取下一篇文章。');
    }

    public function startWriting(Request $request, int $article): RedirectResponse
    {
        try {
            $this->writerPool->startWriting($article, (int) $request->user()->id);

            return back()->with('success', '已开始写作。');
        } catch (DomainException $exception) {
            return back()->withErrors(['content' => $exception->getMessage()]);
        }
    }

    public function release(Request $request, int $article): RedirectResponse
    {
        try {
            $this->writerPool->release($article, (int) $request->user()->id);

            return redirect()->route('content.index')->with('success', '文章已释放回创作池。');
        } catch (DomainException $exception) {
            return back()->withErrors(['content' => $exception->getMessage()]);
        }
    }

    public function submitDocument(Request $request, int $article): RedirectResponse
    {
        $validated = $request->validate(['document_url' => ['required', 'url', 'max:2000']]);

        try {
            $version = $this->documents->submit($article, (int) $request->user()->id, $validated['document_url']);

            return back()->with('success', 'Google Doc 已导入并保存 Writer Original v'.$version.'。');
        } catch (DocumentImportException $exception) {
            return back()->withErrors(['document_url' => $exception->reason]);
        }
    }

    public function checkDocument(Request $request, int $article): RedirectResponse
    {
        try {
            $changed = $this->documents->checkChanged(
                $article,
                (int) $request->user()->id,
                $request->user()->can('content.manage'),
            );

            return back()->with('success', $changed ? 'Google Doc 提交后有新修改。' : 'Google Doc 与当前 Writer Original 一致。');
        } catch (DocumentImportException $exception) {
            return back()->withErrors(['document' => $exception->reason]);
        }
    }

    public function reimportDocument(Request $request, int $article): RedirectResponse
    {
        try {
            $version = $this->documents->reimport(
                $article,
                (int) $request->user()->id,
                $request->user()->can('content.manage'),
            );

            return back()->with('success', '已明确重新导入为 Writer Original v'.$version.'。');
        } catch (DocumentImportException $exception) {
            return back()->withErrors(['document' => $exception->reason]);
        }
    }

    public function runEditorial(Request $request, int $article): RedirectResponse
    {
        try {
            $version = $this->editorial->run($article, (int) $request->user()->id);

            return back()->with('success', 'AI Editorial 已生成 Editorial v'.$version.'。');
        } catch (EditorialException $exception) {
            return back()->withErrors(['editorial' => $exception->reason]);
        }
    }

    public function preparePreview(Request $request, int $article): RedirectResponse
    {
        try {
            $version = $this->publication->preparePreview($article, (int) $request->user()->id);

            return back()->with('success', 'Layout Preview 已生成 v'.$version.'。');
        } catch (PublicationException $exception) {
            return back()->withErrors(['publication' => $exception->reason]);
        }
    }

    public function resolvePublicationVerification(Request $request, int $article): RedirectResponse
    {
        try {
            $this->publication->resolveVerification($article, (int) $request->user()->id);

            return back()->with('success', '发布前核实项已明确确认完成。');
        } catch (PublicationException $exception) {
            return back()->withErrors(['publication' => $exception->reason]);
        }
    }

    public function submitOwnerUat(Request $request, int $article): RedirectResponse
    {
        try {
            $this->publication->submitOwnerUat($article, (int) $request->user()->id);

            return back()->with('success', 'WordPress Draft 已同步并进入 Owner UAT；系统不会自动发布。');
        } catch (PublicationException $exception) {
            return back()->withErrors(['publication' => $exception->reason]);
        }
    }

    public function approveWriter(Request $request, int $article): RedirectResponse
    {
        try {
            $this->publication->approveWriter($article, (int) $request->user()->id);

            return back()->with('success', 'Writer PASS 已记录。');
        } catch (PublicationException $exception) {
            return back()->withErrors(['publication' => $exception->reason]);
        }
    }

    public function approveFact(Request $request, int $article): RedirectResponse
    {
        try {
            $this->publication->approveFact($article, (int) $request->user()->id);

            return back()->with('success', 'Fact PASS 已记录。');
        } catch (PublicationException $exception) {
            return back()->withErrors(['publication' => $exception->reason]);
        }
    }

    public function approveSeo(Request $request, int $article): RedirectResponse
    {
        try {
            $this->publication->approveSeo($article, (int) $request->user()->id);

            return back()->with('success', 'SEO PASS 已记录。');
        } catch (PublicationException $exception) {
            return back()->withErrors(['publication' => $exception->reason]);
        }
    }

    public function publish(Request $request, int $article): RedirectResponse
    {
        try {
            $result = $this->publication->publish($article, (int) $request->user()->id);

            return back()->with('success', '内容已发布'.(isset($result['url']) ? '：'.$result['url'] : '。'));
        } catch (PublicationException $exception) {
            return back()->withErrors(['publication' => $exception->reason]);
        }
    }

    public function addScope(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'project_key' => ['required', 'string', 'max:64', 'exists:business_projects,project_key'],
            'language' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z-]+$/'],
        ]);

        $this->writerScopes->add(
            (int) $validated['user_id'],
            $validated['project_key'],
            $validated['language'],
        );

        return back()->with('success', '写手内容范围已添加。');
    }

    public function removeScope(int $scope): RedirectResponse
    {
        $this->writerScopes->remove($scope);

        return back()->with('success', '写手内容范围已移除。');
    }

    /** @return list<array<string, mixed>> */
    private function writers(): array
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('key', RolePreset::Writer->roleKey()))
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user): array => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
                'scopes' => $this->writerScopes->forUser((int) $user->id),
            ])
            ->all();
    }

    /** @return list<array{project_key:string,name:string}> */
    private function projects(): array
    {
        return DB::table('business_projects')
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['project_key', 'name'])
            ->map(fn ($project): array => [
                'project_key' => (string) $project->project_key,
                'name' => (string) $project->name,
            ])
            ->all();
    }
}
