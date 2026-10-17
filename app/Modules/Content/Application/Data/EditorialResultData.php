<?php

namespace App\Modules\Content\Application\Data;

final readonly class EditorialResultData
{
    /**
     * @param  list<array{type:string,level:?int,text:string,items:list<string>}>  $blocks
     * @param  list<string>  $changeSummary
     * @param  list<array{claim:string,reason:string}>  $verificationRequired
     */
    public function __construct(
        public string $provider,
        public string $model,
        public string $title,
        public array $blocks,
        public array $changeSummary,
        public array $verificationRequired,
    ) {}

    /** @return array<string,mixed> */
    public function snapshot(EditorialRequestData $request): array
    {
        return [
            'schema' => 'content-editorial-v1',
            'source' => [
                'type' => $request->sourceType,
                'version' => $request->sourceVersion,
                'hash' => $request->sourceHash,
            ],
            'provider' => ['name' => $this->provider, 'model' => $this->model],
            'title' => $this->title,
            'blocks' => $this->blocks,
            'change_summary' => $this->changeSummary,
            'verification_required' => $this->verificationRequired,
        ];
    }
}
