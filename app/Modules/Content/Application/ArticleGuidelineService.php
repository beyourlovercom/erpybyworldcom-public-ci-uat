<?php

namespace App\Modules\Content\Application;

use App\Modules\Content\Application\Contracts\BrandContextProvider;
use App\Modules\Content\Application\Contracts\GuidelineRepository;
use App\Modules\Content\Application\Contracts\InternalLinkProvider;
use App\Modules\Content\Application\Data\ArticleBaseData;

final readonly class ArticleGuidelineService
{
    public function __construct(
        private GuidelineRepository $articles,
        private BrandContextProvider $brands,
        private InternalLinkProvider $links,
    ) {
    }

    /** @return array<string, mixed> */
    public function generate(int $articleId): array
    {
        if ($existing = $this->articles->existing($articleId)) {
            return $existing;
        }

        $article = $this->articles->articleBase($articleId);
        $brand = $this->brands->contextFor($article);
        $internalLinks = $this->mergeLinks($article->internalLinkTargets, $this->links->linksFor($article));
        $wordCount = $article->recommendedWordCount ?? $this->defaultWordCount($article->contentType);
        $questions = $this->questions($article);
        $structure = $article->recommendedStructure ?: $this->structure($article, $questions);

        $prohibitedClaims = array_values(array_unique(array_filter([
            ...$article->prohibitedClaims,
            ...($brand['prohibited_claims'] ?? []),
            'Do not invent product specifications, certifications, prices, field results, or quantified performance.',
            'Do not present unsupported assumptions as verified facts.',
        ])));

        $guideline = [
            'version' => 'CONTENT-GUIDELINE-V1',
            'primary_keyword' => $article->primaryKeyword,
            'supporting_keywords' => $article->supportingKeywords,
            'search_intent' => $article->searchIntent,
            'target_audience' => $article->targetAudience ?? ($brand['target_audience'] ?? null),
            'article_objective' => $article->contentObjective ?? $this->objective($article),
            'title_direction' => $article->titleDirection,
            'recommended_structure' => $structure,
            'questions_to_answer' => $questions,
            'recommended_word_count' => $wordCount,
            'internal_links' => $internalLinks,
            'external_references' => $article->referenceSources,
            'available_evidence' => $article->evidenceSummary,
            'prohibited_claims' => $prohibitedClaims,
            'data_requiring_verification' => [
                'current price or cost claims',
                'exact product dimensions or performance numbers not present in supplied evidence',
                'certifications, standards, regulations, and market-specific compliance claims',
                'competitor claims and third-party statistics',
            ],
            'brand_tone' => $brand['tone'] ?? 'Professional, practical, clear, evidence-led.',
            'language' => $article->language,
            'cta_direction' => $brand['cta_direction'] ?? 'Invite the reader to provide project requirements for a relevant recommendation or quotation.',
            'submission_instructions' => [
                'Write the full article in Google Docs, not in ERP and not in WordPress.',
                'Keep headings and lists structurally clean so they can be imported.',
                'Add source links for facts that require external verification.',
                'When the draft is complete, submit the Google Doc URL in ERP.',
            ],
        ];

        $this->articles->saveIfMissing($articleId, $guideline);

        return $this->articles->existing($articleId) ?? $guideline;
    }

    /** @param list<array<string, mixed>> $existing @param list<array<string, mixed>> $provided */
    private function mergeLinks(array $existing, array $provided): array
    {
        $merged = [];
        foreach ([...$existing, ...$provided] as $link) {
            $key = (string) ($link['url'] ?? $link['target_url'] ?? json_encode($link));
            $merged[$key] = $link;
        }

        return array_values($merged);
    }

    /** @return list<string> */
    private function questions(ArticleBaseData $article): array
    {
        $topic = $article->primaryKeyword ?: $article->titleDirection;
        $common = [
            "What is {$topic}, and when is it relevant?",
            "Which factors should a buyer evaluate before choosing {$topic}?",
            'What practical mistakes or misunderstandings should the reader avoid?',
            'Which facts vary by project, product specification, market, or supplier and therefore require verification?',
        ];

        if ($article->contentType === 'comparison') {
            array_splice($common, 2, 0, [
                'What are the meaningful differences, trade-offs, and suitable use cases between the compared options?',
            ]);
        }

        if ($article->contentType === 'guide') {
            array_splice($common, 2, 0, [
                'What practical sequence should the reader follow from planning to selection and implementation?',
            ]);
        }

        return $common;
    }

    /** @param list<string> $questions @return list<array{level:string,direction:string}> */
    private function structure(ArticleBaseData $article, array $questions): array
    {
        $headings = [];
        foreach ($questions as $question) {
            $headings[] = ['level' => 'H2', 'direction' => $question];
        }
        $headings[] = ['level' => 'H2', 'direction' => 'Frequently asked questions'];
        $headings[] = ['level' => 'H2', 'direction' => 'Next step / project-specific CTA'];

        return $headings;
    }

    private function defaultWordCount(string $contentType): int
    {
        return match ($contentType) {
            'comparison' => 1800,
            'guide' => 1600,
            default => 1400,
        };
    }

    private function objective(ArticleBaseData $article): string
    {
        $topic = $article->primaryKeyword ?: $article->titleDirection;

        return "Answer the reader's intent around {$topic} with useful, verifiable guidance and a clear next step.";
    }
}
