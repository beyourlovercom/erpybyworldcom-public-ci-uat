<?php

namespace App\Modules\Content\Infrastructure\Yby;

use App\Modules\Content\Application\Contracts\BrandContextProvider;
use App\Modules\Content\Application\Data\ArticleBaseData;

final class YbyBrandContextProvider implements BrandContextProvider
{
    public function contextFor(ArticleBaseData $article): array
    {
        if ($article->projectKey === 'yby-irrigation') {
            return [
                'brand_name' => 'YBY Irrigation',
                'target_audience' => 'Irrigation distributors, farm/project buyers, contractors, and procurement decision makers.',
                'tone' => 'Professional, practical, technically clear, B2B-oriented, and evidence-led. Avoid exaggerated marketing language.',
                'cta_direction' => 'Invite the reader to share crop/project area, water source, pressure/flow, required quantity, and destination so YBY can recommend a suitable configuration or quotation.',
                'prohibited_claims' => [
                    'Do not claim a specification is available unless it exists in supplied product/data evidence.',
                    'Do not claim universal savings, yield gains, lifespan, or ROI without a cited source.',
                ],
            ];
        }

        return [
            'brand_name' => $article->brandKey ?: $article->projectKey,
            'target_audience' => 'B2B buyers and project decision makers.',
            'tone' => 'Professional, practical, clear, and evidence-led.',
            'cta_direction' => 'Invite the reader to share project requirements for a relevant recommendation or quotation.',
            'prohibited_claims' => [],
        ];
    }
}
